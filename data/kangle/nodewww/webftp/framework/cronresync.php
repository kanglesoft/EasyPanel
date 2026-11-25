<?php
/**
 * framework/cronresync.php —— 系统计划任务重建 / 执行统一入口（CLI 专用）
 *
 * 单元：admin-system-cron。两类调用方：
 *
 *  A) 容器启动钩子（kangle/entrypoint.sh 末尾）：
 *       php74 <本文件>
 *     依次执行：
 *       1. @apicall('admincron','install_all')   —— 归属单一定为 admincron.api.php
 *          （建表兜底 + 全量重渲染 /etc/cron.d/ep_admincron），评审#6；
 *       2. 对 framework/api/{healthprobe,certtask,remotestorage}.api.php：
 *          **文件存在才调** install_cron（这些单元尚未合入时静默跳过）。
 *     /etc/cron.d 属容器运行时目录、镜像重建即丢失，本钩子即「重建通道」：
 *     任务定义持久化在 vhs.db（bind 挂载），启动时据此重现 cron.d。
 *
 *  B) cron.d 任务行与面板「立即执行」：
 *       php74 <本文件> run <task_id>
 *     转 apicall('admincron','run', id)，日志由 AdmincronAPI 自行落盘。
 *
 * 引导方式对齐 framework/shell.php / crontab.php：定义 SYS_ROOT、APPLICATON_ROOT
 * 后 include runtime.php；拒绝 Web 模式，避免该入口被 HTTP 直接触发。
 * 输出走 stdout（entrypoint 重定向到容器日志；cron.d 行重定向 /dev/null）。
 */
@set_time_limit(0);
date_default_timezone_set('Asia/Shanghai');
$dir = dirname(__FILE__);
define('SYS_ROOT', $dir);
define('APPLICATON_ROOT', '');
include SYS_ROOT . '/runtime.php';

if ($_SERVER['argv'] == null || $_REQUEST != null) {
	exit("cronresync cann't run in web model. please run in cli.\n");
}

$argv = $_SERVER['argv'];
$program = array_shift($argv);
$action = array_shift($argv);
$action = $action === null ? 'resync' : strtolower($action);

if ($action === 'run') {
	$id = isset($argv[0]) ? intval($argv[0]) : 0;
	$ok = @apicall('admincron', 'run', array($id));
	exit($ok ? 0 : 1);
}

if ($action !== 'resync') {
	exit("Usage: {$program} [resync|run <task_id>]\n");
}

// ── resync ──
// 1) 本单元自身：admin_cron 表兜底 + ep_admincron 全量重建（唯一写者 admincron.api.php）。
$r = @apicall('admincron', 'install_all', array());
echo '[cronresync] admincron install_all: ' . ($r === false ? 'FAILED' : 'ok') . "\n";

// 2) 其它单元的定时任务：api 文件存在才调 install_cron（合入前静默跳过）。
foreach (array('healthprobe', 'certtask', 'remotestorage') as $mod) {
	$api_file = SYS_ROOT . '/api/' . $mod . '.api.php';

	if (!is_file($api_file)) {
		echo '[cronresync] skip ' . $mod . ' (api file not present yet)' . "\n";
		continue;
	}

	$rr = @apicall($mod, 'install_cron', array());
	echo '[cronresync] ' . $mod . ' install_cron: ' . ($rr === false ? 'FAILED' : 'ok') . "\n";
}

// 3) cert-renewal-cdn-distribution：同步段之后立即跑一轮分发任务（cronresync 兼做立即执行，
//    与 certtask 集成片段一致；install_cron 已在上面循环里调过，runOnce 幂等可重复）。
if (is_file(SYS_ROOT . '/api/certtask.api.php')) {
	$rc = @apicall('certtask', 'runOnce', array());
	echo '[cronresync] certtask runOnce: ' . ($rc === false ? 'FAILED' : 'ok') . "\n";
}

exit(0);

?>
