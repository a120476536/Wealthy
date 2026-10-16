<?php
namespace app\process;

use support\Db;
use support\Log;
use Workerman\Timer;

/**
 * 收款码自动审核进程
 *
 * 仅当 system_setting.qr_audit_mode = 'auto' 时生效（manual 模式不干预）。
 * 按 system_setting.qr_auto_audit_minutes 配置（默认 1 分钟）：
 * 若用户提交的收款码超过 N 分钟仍未被人工审核（qr_audit_logs.status=1 待审核），
 * 则由系统自动通过审核，回写用户对应渠道状态。
 *
 * qr_auto_audit_minutes 配置为 0 时关闭自动审核（仍需人工审核）。
 * 本进程 count=1，天然单例，避免重复执行。
 */
class QrAutoAudit
{
    public function __construct()
    {
        // 每 30 秒扫描一次超时待审记录
        Timer::add(30, [$this, 'run']);
        Log::info('[QrAutoAudit] timer registered');
    }

    public function run()
    {
        try {
            // 仅「自动审核」模式才超时自动通过；人工审核模式不干预
            $mode = site_setting('qr_audit_mode', 'manual');
            if ($mode !== 'auto') {
                return;
            }

            $minutes = (int) site_setting('qr_auto_audit_minutes', 1);
            if ($minutes <= 0) {
                return; // 配置为 0 时关闭自动审核
            }

            $threshold = date('Y-m-d H:i:s', time() - $minutes * 60);
            $rows = Db::table('qr_audit_logs')
                ->where('status', 1) // 待审核
                ->where('created_at', '<=', $threshold)
                ->orderBy('id', 'asc')
                ->limit(200)
                ->get();

            if (empty($rows)) {
                return;
            }

            foreach ($rows as $row) {
                $statusField = $row->channel == 1 ? 'wechat_qr_status' : 'alipay_qr_status';
                $reasonField = $row->channel == 1 ? 'wechat_qr_reason' : 'alipay_qr_reason';

                // 幂等：仅当仍是待审核（status=1）才通过，避免与人工审核并发冲突
                $affected = Db::table('qr_audit_logs')
                    ->where('id', $row->id)
                    ->where('status', 1)
                    ->update([
                        'status'      => 2,
                        'reviewed_at' => date('Y-m-d H:i:s'),
                        'admin_id'    => 0, // 0 表示系统自动处理
                        'reason'      => "系统超时({$minutes}分钟)自动通过",
                    ]);

                if ($affected > 0) {
                    Db::table('users')
                        ->where('id', $row->user_id)
                        ->update([
                            $statusField => 2,
                            $reasonField => null,
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                    Log::info("[QrAutoAudit] auto-pass log_id={$row->id} user_id={$row->user_id} channel={$row->channel}");
                }
            }
        } catch (\Throwable $e) {
            Log::error('[QrAutoAudit] error: ' . $e->getMessage());
        }
    }
}
