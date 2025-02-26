<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ann;
use App\Models\DetectLog;
use App\Models\EmailQueue;
use App\Models\Invoice;
use App\Models\Node;
use App\Models\OnlineLog;
use App\Models\Order;
use App\Models\PasswordReset;
use App\Models\Paylist;
use App\Models\Setting;
use App\Models\StreamMedia;
use App\Models\TelegramSession;
use App\Models\User;
use App\Models\UserHourlyUsage;
use App\Models\UserSubscribeLog;
use App\Utils\Telegram;
use App\Utils\Tools;
use DateTime;
use Exception;
use Psr\Http\Client\ClientExceptionInterface;
use Telegram\Bot\Exceptions\TelegramSDKException;
use function array_map;
use function date;
use function in_array;
use function json_decode;
use function max;
use function str_replace;
use function strtotime;
use function time;
use const PHP_EOL;

final class CronJob
{
    public static function addTrafficLog(): void
    {
        $users = User::all();

        foreach ($users as $user) {
            $transfer_total = $user->transfer_total;
            $transfer_total_last = UserHourlyUsage::where('user_id', $user->id)->orderBy('id', 'desc')->first();

            if ($transfer_total_last === null) {
                $transfer_total_last = 0;
            } else {
                $transfer_total_last = $transfer_total_last->traffic;
            }

            $trafficlog = new UserHourlyUsage();
            $trafficlog->user_id = $user->id;
            $trafficlog->traffic = $transfer_total;
            $trafficlog->hourly_usage = $transfer_total - $transfer_total_last;
            $trafficlog->datetime = time();
            $trafficlog->save();
        }
    }

    public static function cleanDb(): void
    {
        UserSubscribeLog::where('request_time', '<', date('Y-m-d H:i:s', time() - 86400 * (int) $_ENV['subscribeLog_keep_days']))->delete();
        UserHourlyUsage::where('datetime', '<', time() - 86400 * (int) $_ENV['trafficLog_keep_days'])->delete();
        DetectLog::where('datetime', '<', time() - 86400 * 3)->delete();
        EmailQueue::where('time', '<', time() - 86400)->delete();
        PasswordReset::where('expire_time', '<', time() - 86400)->delete();
        OnlineLog::where('last_time', '<', time() - 86400)->delete();
        StreamMedia::where('created_at', '<', time() - 86400)->delete();
        TelegramSession::where('datetime', '<', time() - 900)->delete();

        echo date('Y-m-d H:i:s') . ' 数据库清理完成' . PHP_EOL;
    }

    public static function cleanUser(): void
    {
        $freeUsers = User::where('class', 0)->get();

        foreach ($freeUsers as $user) {
            if (
                $_ENV['account_expire_delete_days'] >= 0 &&
                strtotime($user->expire_in) + $_ENV['account_expire_delete_days'] * 86400 < time() &&
                $user->money <= $_ENV['auto_clean_min_money']
            ) {
                $user->sendMail(
                    $_ENV['appName'] . '-你的账户因为过期被删除了',
                    'warn.tpl',
                    [
                        'text' => '你好，系统发现你的账户已经过期 ' . $_ENV['account_expire_delete_days'] . ' 天了，帐号已经被删除。',
                    ],
                    [],
                    true
                );
                $user->killUser();
                continue;
            }

            if (
                $_ENV['auto_clean_uncheck_days'] > 0 &&
                max(
                    $user->last_check_in_time,
                    strtotime($user->reg_date)
                ) + ($_ENV['auto_clean_uncheck_days'] * 86400) < time() &&
                $user->class === 0 &&
                $user->money <= $_ENV['auto_clean_min_money']
            ) {
                $user->sendMail(
                    $_ENV['appName'] . '-你的账户因为未签到被删除了',
                    'warn.tpl',
                    [
                        'text' => '你好，系统发现你的账号已经 ' . $_ENV['auto_clean_uncheck_days'] . ' 天没签到了，帐号已经被删除。',
                    ],
                    [],
                    true
                );
                $user->killUser();
                continue;
            }

            if (
                $_ENV['auto_clean_unused_days'] > 0 &&
                max($user->t, strtotime($user->reg_date)) + ($_ENV['auto_clean_unused_days'] * 86400) < time() &&
                $user->class === 0 &&
                $user->money <= $_ENV['auto_clean_min_money']
            ) {
                $user->sendMail(
                    $_ENV['appName'] . '-你的账户因为闲置被删除了',
                    'warn.tpl',
                    [
                        'text' => '你好，系统发现你的账号已经 ' . $_ENV['auto_clean_unused_days'] . ' 天没使用了，帐号已经被删除。',
                    ],
                    [],
                    true
                );
                $user->killUser();
            }
        }

        echo date('Y-m-d H:i:s') . ' 免费用户清理完成' . PHP_EOL;
    }

    /**
     * @throws TelegramSDKException
     */
    public static function detectNodeOffline(): void
    {
        $nodes = Node::where('type', 1)->get();
        $adminUsers = User::where('is_admin', '=', '1')->get();

        foreach ($nodes as $node) {
            if ($node->getNodeOnlineStatus() >= 0 && $node->online === 1) {
                continue;
            }

            if ($node->getNodeOnlineStatus() === -1 && $node->online === 1) {
                foreach ($adminUsers as $user) {
                    echo 'Send Node Offline Email to admin user: ' . $user->id . PHP_EOL;
                    $user->sendMail(
                        $_ENV['appName'] . '-系统警告',
                        'warn.tpl',
                        [
                            'text' => '管理员你好，系统发现节点 ' . $node->name . ' 掉线了，请你及时处理。',
                        ],
                        [],
                        false
                    );
                    $notice_text = str_replace(
                        '%node_name%',
                        $node->name,
                        Setting::obtain('telegram_node_offline_text')
                    );
                }

                if (Setting::obtain('telegram_node_offline')) {
                    Telegram::send($notice_text);
                }

                $node->online = false;
                $node->save();

                continue;
            }

            foreach ($adminUsers as $user) {
                echo 'Send Node Online Email to admin user: ' . $user->id . PHP_EOL;
                $user->sendMail(
                    $_ENV['appName'] . '-系统提示',
                    'warn.tpl',
                    [
                        'text' => '管理员你好，系统发现节点 ' . $node->name . ' 恢复上线了。',
                    ],
                    [],
                    false
                );
                $notice_text = str_replace(
                    '%node_name%',
                    $node->name,
                    Setting::obtain('telegram_node_online_text')
                );
            }

            if (Setting::obtain('telegram_node_online')) {
                Telegram::send($notice_text);
            }

            $node->online = true;
            $node->save();
        }

        echo date('Y-m-d H:i:s') . ' 节点离线检测完成' . PHP_EOL;
    }

    public static function expirePaidUserAccount(): void
    {
        $paidUsers = User::where('class', '>', 0)->get();

        foreach ($paidUsers as $user) {
            if (strtotime($user->class_expire) < time()) {
                $text = '你好，系统发现你的账号等级已经过期了。';
                $reset_traffic = $_ENV['class_expire_reset_traffic'];

                if ($reset_traffic >= 0) {
                    $user->transfer_enable = Tools::toGB($reset_traffic);
                    $text .= '流量已经被重置为' . $reset_traffic . 'GB';
                }

                $user->sendMail(
                    $_ENV['appName'] . '-你的账户等级已经过期了',
                    'warn.tpl',
                    [
                        'text' => $text,
                    ],
                    [],
                    true
                );

                $user->u = 0;
                $user->d = 0;
                $user->transfer_today = 0;
                $user->class = 0;
                $user->save();
            }
        }

        echo date('Y-m-d H:i:s') . ' 付费用户过期检测完成' . PHP_EOL;
    }

    // This shit should be removed but kept for compatibility reason, for now. User account should never expire.
    public static function expireFreeUserAccount(): void
    {
        $freeUsers = User::where('class', 0)->get();

        foreach ($freeUsers as $user) {
            if (strtotime($user->expire_in) < time() && ! $user->expire_notified) {
                $user->transfer_enable = 0;
                $user->u = 0;
                $user->d = 0;
                $user->transfer_today = 0;

                $user->sendMail(
                    $_ENV['appName'] . '-你的账户已经过期了',
                    'warn.tpl',
                    [
                        'text' => '你好，系统发现你的账号已经过期了。',
                    ],
                    [],
                    true
                );

                $user->expire_notified = true;
                $user->save();
            } elseif (strtotime($user->expire_in) > time() && $user->expire_notified) {
                $user->expire_notified = false;
                $user->save();
            }
        }

        echo date('Y-m-d H:i:s') . ' 免费用户过期检测完成' . PHP_EOL;
    }

    public static function processEmailQueue(): void
    {
        //记录当前时间戳
        $timestamp = time();
        //邮件队列处理
        while (true) {
            if (time() - $timestamp > 299) {
                echo date('Y-m-d H:i:s') . '邮件队列处理超时，已跳过' . PHP_EOL;
                break;
            }
            DB::beginTransaction();
            $email_queues_raw = DB::select('SELECT * FROM email_queue LIMIT 1 FOR UPDATE SKIP LOCKED');
            if (count($email_queues_raw) === 0) {
                DB::commit();
                break;
            }
            $email_queues = array_map(static function ($value) {
                return (array) $value;
            }, $email_queues_raw);
            $email_queue = $email_queues[0];
            echo '发送邮件至 ' . $email_queue['to_email'] . PHP_EOL;
            DB::delete('DELETE FROM email_queue WHERE id = ?', [$email_queue['id']]);
            if (Tools::isEmail($email_queue['to_email'])) {
                try {
                    Mail::send($email_queue['to_email'], $email_queue['subject'], $email_queue['template'], json_decode($email_queue['array']));
                } catch (Exception|ClientExceptionInterface $e) {
                    echo $e->getMessage();
                }
            } else {
                echo $email_queue['to_email'] . ' 邮箱格式错误，已跳过' . PHP_EOL;
            }
            DB::commit();
        }

        echo date('Y-m-d H:i:s') . ' 邮件队列处理完成' . PHP_EOL;
    }

    public static function processOrderActivation(): void
    {
        $users = User::all();

        foreach ($users as $user) {
            $user_id = $user->id;
            // 获取用户账户等待激活的订单
            $pending_activation_orders = Order::where('user_id', $user_id)->where('status', 'pending_activation')->orderBy('id', 'asc')->get();
            // 获取用户账户已激活的订单，一个用户同时只能有一个已激活的订单
            $activated_order = Order::where('user_id', $user_id)->where('status', 'activated')->orderBy('id', 'asc')->first();
            // 如果用户账户中没有已激活的订单，且有等待激活的订单，则激活最早的等待激活订单
            if ($activated_order === null && count($pending_activation_orders) > 0) {
                $order = $pending_activation_orders[0];
                // 获取订单内容准备激活
                $content = json_decode($order->product_content);
                // 激活商品
                $user->u = 0;
                $user->d = 0;
                $user->transfer_today = 0;
                $user->transfer_enable = Tools::toGB($content->bandwidth);
                $user->class = $content->class;
                $old_expire_in = new DateTime();
                $old_class_expire = new DateTime();
                $user->expire_in = $old_expire_in->modify('+' . $content->time . ' days')->format('Y-m-d H:i:s');
                $user->class_expire = $old_class_expire->modify('+' . $content->class_time . ' days')->format('Y-m-d H:i:s');
                $user->node_group = $content->node_group;
                $user->node_speedlimit = $content->speed_limit;
                $user->node_iplimit = $content->ip_limit;
                $user->save();
                $order->status = 'activated';
                $order->update_time = time();
                $order->save();
                echo "订单 #{$order->id} 已激活。\n";
                continue;
            }
            // 如果用户账户中有已激活的订单，则判断是否过期
            if ($activated_order !== null) {
                $content = json_decode($activated_order->product_content);
                if ($activated_order->update_time + $content->time * 86400 < time()) {
                    $activated_order->status = 'expired';
                    $activated_order->update_time = time();
                    $activated_order->save();
                    echo "订单 #{$activated_order->id} 已过期。\n";
                }
            }
        }

        echo date('Y-m-d H:i:s') . ' 订单激活处理完成' . PHP_EOL;
    }

    public static function processPendingOrder(): void
    {
        $pending_payment_orders = Order::where('status', 'pending_payment')->get();

        foreach ($pending_payment_orders as $order) {
            // 检查账单支付状态
            $invoice = Invoice::where('order_id', $order->id)->first();

            if ($invoice === null) {
                continue;
            }
            // 标记订单为等待激活
            if (in_array($invoice->status, ['paid_gateway', 'paid_balance', 'paid_admin'])) {
                $order->status = 'pending_activation';
                $order->update_time = time();
                $order->save();
                echo "已标记订单 #{$order->id} 为等待激活。\n";
                continue;
            }
            // 取消超时未支付的订单和关联账单
            if ($order->create_time + 86400 < time()) {
                $order->status = 'cancelled';
                $order->update_time = time();
                $order->save();
                echo "已取消超时订单 #{$order->id}。\n";
                $invoice->status = 'cancelled';
                $invoice->update_time = time();
                $invoice->save();
                echo "已取消超时账单 #{$invoice->id}。\n";
            }
        }

        echo date('Y-m-d H:i:s') . ' 等待中订单处理完成' . PHP_EOL;
    }

    public static function resetNodeBandwidth(): void
    {
        Node::where('bandwidthlimit_resetday', date('d'))->update(['node_bandwidth' => 0]);

        echo date('Y-m-d H:i:s') . ' 重设节点流量完成' . PHP_EOL;
    }

    public static function resetTodayTraffic(): void
    {
        User::query()->update(['transfer_today' => 0]);

        echo date('Y-m-d H:i:s') . ' 重设用户每日流量完成' . PHP_EOL;
    }

    public static function resetFreeUserTraffic(): void
    {
        $freeUsers = User::where('class', 0)->where('auto_reset_day', date('d'))->get();

        foreach ($freeUsers as $user) {
            $user->u = 0;
            $user->d = 0;
            $user->transfer_enable = $user->auto_reset_bandwidth * 1024 * 1024 * 1024;
            $user->save();

            $user->sendMail(
                $_ENV['appName'] . '-你的免费流量被重置了',
                'warn.tpl',
                [
                    'text' => '你好，你的免费流量已经被重置为' . $user->auto_reset_bandwidth . 'GB',
                ],
                [],
                true
            );
        }

        echo date('Y-m-d H:i:s') . ' 重设免费用户流量完成' . PHP_EOL;
    }

    public static function sendDailyFinanceMail(): void
    {
        $today = strtotime('00:00:00');
        $paylists = Paylist::where('status', 1)->whereBetween('datetime', [strtotime('-1 day', $today), $today])->get();
        $text_html = '<table border=1><tr><td>金额</td><td>用户ID</td><td>用户名</td><td>充值时间</td>';

        foreach ($paylists as $paylist) {
            $text_html .= '<tr>';
            $text_html .= '<td>' . $paylist->total . '</td>';
            $text_html .= '<td>' . $paylist->userid . '</td>';
            $text_html .= '<td>' . User::find($paylist->userid)->user_name . '</td>';
            $text_html .= '<td>' . Tools::toDateTime((int) $paylist->datetime) . '</td>';
            $text_html .= '</tr>';
        }

        $text_html .= '</table>';
        $text_html .= '<br>昨日总收入笔数：' . count($paylists) . '<br>昨日总收入金额：' . $paylists->sum('total');
        $adminUser = User::where('is_admin', '=', '1')->get();

        foreach ($adminUser as $user) {
            echo 'Sending daily finance email to admin user: ' . $user->id . PHP_EOL;
            $user->sendMail(
                $_ENV['appName'] . '-财务日报',
                'finance.tpl',
                [
                    'title' => '财务日报',
                    'text' => $text_html,
                ],
                []
            );
        }

        echo date('Y-m-d H:i:s') . ' 成功发送财务日报' . PHP_EOL;
    }

    public static function sendWeeklyFinanceMail(): void
    {
        $today = strtotime('00:00:00');
        $paylists = Paylist::where('status', 1)->whereBetween('datetime', [strtotime('-1 week', $today), $today])->get();

        $text_html = '<br>上周总收入笔数：' . count($paylists) . '<br>上周总收入金额：' . $paylists->sum('total');
        $adminUser = User::where('is_admin', '=', '1')->get();

        foreach ($adminUser as $user) {
            echo 'Sending weekly finance email to admin user: ' . $user->id . PHP_EOL;
            $user->sendMail(
                $_ENV['appName'] . '-财务周报',
                'finance.tpl',
                [
                    'title' => '财务周报',
                    'text' => $text_html,
                ],
                []
            );
        }

        echo date('Y-m-d H:i:s') . ' 成功发送财务周报' . PHP_EOL;
    }

    public static function sendMonthlyFinanceMail(): void
    {
        $today = strtotime('00:00:00');
        $paylists = Paylist::where('status', 1)->whereBetween('datetime', [strtotime('-1 month', $today), $today])->get();

        $text_html = '<br>上月总收入笔数：' . count($paylists) . '<br>上月总收入金额：' . $paylists->sum('total');
        $adminUser = User::where('is_admin', '=', '1')->get();

        foreach ($adminUser as $user) {
            echo 'Sending monthly finance email to admin user: ' . $user->id . PHP_EOL;
            $user->sendMail(
                $_ENV['appName'] . '-财务月报',
                'finance.tpl',
                [
                    'title' => '财务月报',
                    'text' => $text_html,
                ],
                []
            );
        }

        echo date('Y-m-d H:i:s') . ' 成功发送财务月报' . PHP_EOL;
    }

    public static function sendPaidUserUsageLimitNotification(): void
    {
        $paidUsers = User::where('class', '>', 0)->get();

        foreach ($paidUsers as $user) {
            $user_traffic_left = $user->transfer_enable - $user->u - $user->d;
            $under_limit = false;
            $unit_text = '';

            if (
                $_ENV['notify_limit_mode'] === 'per' &&
                $user_traffic_left / $user->transfer_enable * 100 < $_ENV['notify_limit_value']
            ) {
                $under_limit = true;
                $unit_text = '%';
            } elseif (
                $_ENV['notify_limit_mode'] === 'mb' &&
                Tools::flowToMB($user_traffic_left) < $_ENV['notify_limit_value']
            ) {
                $under_limit = true;
                $unit_text = 'MB';
            }

            if ($under_limit && ! $user->traffic_notified) {
                $result = $user->sendMail(
                    $_ENV['appName'] . '-你的剩余流量过低',
                    'warn.tpl',
                    [
                        'text' => '你好，系统发现你剩余流量已经低于 ' . $_ENV['notify_limit_value'] . $unit_text . ' 。',
                    ],
                    [],
                    true
                );
                if ($result) {
                    $user->traffic_notified = true;
                    $user->save();
                }
            } elseif (! $under_limit && $user->traffic_notified) {
                $user->traffic_notified = false;
                $user->save();
            }
        }

        echo date('Y-m-d H:i:s') . ' 付费用户用量限制提醒完成' . PHP_EOL;
    }

    public static function sendDailyTrafficReport(): void
    {
        $users = User::where('sendDailyMail', 1)->get();

        $ann_latest_raw = Ann::orderBy('date', 'desc')->first();

        if ($ann_latest_raw === null) {
            $ann_latest = '<br><br>';
        } else {
            $ann_latest = $ann_latest_raw->content . '<br><br>';
        }

        foreach ($users as $user) {
            $user->sendDailyNotification($ann_latest);
        }

        echo date('Y-m-d H:i:s') . ' 成功发送每日邮件' . PHP_EOL;
    }

    /**
     * @throws TelegramSDKException
     */
    public static function sendTelegramDailyJob(): void
    {
        Telegram::send(Setting::obtain('telegram_daily_job_text'));

        echo date('Y-m-d H:i:s') . ' 成功发送 Telegram 每日任务提示' . PHP_EOL;
    }

    /**
     * @throws TelegramSDKException
     */
    public static function sendTelegramDiary(): void
    {
        $analytics = new Analytics();

        Telegram::send(
            str_replace(
                [
                    '%getTodayCheckinUser%',
                    '%lastday_total%',
                ],
                [
                    $analytics->getTodayCheckinUser(),
                    $analytics->getTodayTrafficUsage(),
                ],
                Setting::obtain('telegram_diary_text')
            )
        );

        echo date('Y-m-d H:i:s') . ' 成功发送 Telegram 系统运行日志' . PHP_EOL;
    }

    public static function updateNodeIp(): void
    {
        $nodes = Node::where('type', 1)->get();

        foreach ($nodes as $node) {
            $server = $node->server;
            if (! Tools::isIPv4($server) && ! Tools::isIPv6($server)) {
                $node->changeNodeIp($server);
                $node->save();
            }
        }

        echo date('Y-m-d H:i:s') . ' 更新节点 IP 完成' . PHP_EOL;
    }
}
