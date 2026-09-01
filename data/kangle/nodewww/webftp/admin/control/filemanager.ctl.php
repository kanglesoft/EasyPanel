<?php
needRole('admin');
class FilemanagerControl extends Control
{
	/**
	 * 文件管理列表页（T03 / 决策 1：根锚点固定容器内 /home/ftp）
	 *
	 * 安全要点（不要在这里做任何越权判断，全部交给 FilemanagerApi 的四层防护）：
	 *   ① 本控制器首行 needRole('admin') 是唯一鉴权入口；
	 *   ② Control::__construct() 已自动挂载 csrf_guard()，所有写操作经它校验 token；
	 *   ③ 文件操作以 root 运行，路径校验是最后一道兜底，绝不能在此放松。
	 */
	public function index()
	{
		$this->_tpl->assign('breadcrumb', '文件管理');
		return $this->_tpl->fetch('filemanager/ls.html');
	}

	/**
	 * 列举目录（ajax，只读 —— 不进入 CSRF ENFORCE 清单）
	 */
	public function ls()
	{
		$dir = isset($_REQUEST['dir']) ? trim($_REQUEST['dir']) : '/';
		$r = apicall('filemanager', 'ls', array($dir));
		exit(json_encode($r));
	}

	/**
	 * 读取文本文件内容（ajax，只读）
	 */
	public function readFile()
	{
		$dir = isset($_REQUEST['dir']) ? trim($_REQUEST['dir']) : '/';
		$name = isset($_REQUEST['name']) ? trim($_REQUEST['name']) : '';
		$r = apicall('filemanager', 'readFile', array($dir, $name));
		exit(json_encode($r));
	}

	/**
	 * 下载文件（流式，不进 JSON）
	 *
	 * download() 方法内部直接流式输出并 exit，因此本动作不会执行到 return 之后。
	 * 触发方式用 GET（<a>/window.location），guard 对非 POST 只确保 token 存在、放行。
	 */
	public function download()
	{
		$dir = isset($_REQUEST['dir']) ? trim($_REQUEST['dir']) : '/';
		$name = isset($_REQUEST['name']) ? trim($_REQUEST['name']) : '';
		$range = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : '';
		apicall('filemanager', 'download', array($dir, $name, $range));
		exit;
	}

	/**
	 * 写入文本文件（ajax）
	 */
	public function writeFile()
	{
		$dir = isset($_REQUEST['dir']) ? trim($_REQUEST['dir']) : '/';
		$name = isset($_REQUEST['name']) ? trim($_REQUEST['name']) : '';
		$content = isset($_REQUEST['content']) ? $_REQUEST['content'] : '';
		$r = apicall('filemanager', 'writeFile', array($dir, $name, $content));
		exit(json_encode($r));
	}

	/**
	 * 上传（支持分片；单分片或最后一片才落盘）
	 */
	public function upload()
	{
		$dir = isset($_REQUEST['dir']) ? trim($_REQUEST['dir']) : '/';
		$name = isset($_REQUEST['name']) ? trim($_REQUEST['name']) : '';
		$chunkIndex = isset($_REQUEST['chunk']) ? intval($_REQUEST['chunk']) : 0;
		$totalChunks = isset($_REQUEST['chunks']) ? intval($_REQUEST['chunks']) : 1;
		$r = apicall('filemanager', 'upload', array($dir, $name, $chunkIndex, $totalChunks));
		exit(json_encode($r));
	}

	/**
	 * 新建目录（ajax）
	 */
	public function mkdir()
	{
		$dir = isset($_REQUEST['dir']) ? trim($_REQUEST['dir']) : '/';
		$name = isset($_REQUEST['name']) ? trim($_REQUEST['name']) : '';
		$r = apicall('filemanager', 'mkdir', array($dir, $name));
		exit(json_encode($r));
	}

	/**
	 * 重命名（ajax）
	 */
	public function rename()
	{
		$dir = isset($_REQUEST['dir']) ? trim($_REQUEST['dir']) : '/';
		$src = isset($_REQUEST['src']) ? trim($_REQUEST['src']) : '';
		$dst = isset($_REQUEST['dst']) ? trim($_REQUEST['dst']) : '';
		$r = apicall('filemanager', 'rename', array($dir, $src, $dst));
		exit(json_encode($r));
	}

	/**
	 * 复制（ajax）
	 */
	public function copy()
	{
		$dir = isset($_REQUEST['dir']) ? trim($_REQUEST['dir']) : '/';
		$src = isset($_REQUEST['src']) ? trim($_REQUEST['src']) : '';
		$dst = isset($_REQUEST['dst']) ? trim($_REQUEST['dst']) : '';
		$r = apicall('filemanager', 'copy', array($dir, $src, $dst));
		exit(json_encode($r));
	}

	/**
	 * 移动（ajax）
	 */
	public function move()
	{
		$dir = isset($_REQUEST['dir']) ? trim($_REQUEST['dir']) : '/';
		$src = isset($_REQUEST['src']) ? trim($_REQUEST['src']) : '';
		$dst = isset($_REQUEST['dst']) ? trim($_REQUEST['dst']) : '';
		$r = apicall('filemanager', 'move', array($dir, $src, $dst));
		exit(json_encode($r));
	}

	/**
	 * 删除（ajax，不可逆；前端应二次确认）
	 */
	public function delete()
	{
		$dir = isset($_REQUEST['dir']) ? trim($_REQUEST['dir']) : '/';
		$name = isset($_REQUEST['name']) ? trim($_REQUEST['name']) : '';
		$r = apicall('filemanager', 'delete', array($dir, $name));
		exit(json_encode($r));
	}

	/**
	 * 批量删除（ajax）
	 */
	public function batchDelete()
	{
		$dir = isset($_REQUEST['dir']) ? trim($_REQUEST['dir']) : '/';
		$names = isset($_REQUEST['names']) ? $_REQUEST['names'] : array();

		if (!is_array($names)) {
			$names = array();
		}

		$r = apicall('filemanager', 'batchDelete', array($dir, $names));
		exit(json_encode($r));
	}

	/**
	 * 修改权限（ajax）
	 */
	public function chmod()
	{
		$dir = isset($_REQUEST['dir']) ? trim($_REQUEST['dir']) : '/';
		$name = isset($_REQUEST['name']) ? trim($_REQUEST['name']) : '';
		$mode = isset($_REQUEST['mode']) ? trim($_REQUEST['mode']) : '';
		$r = apicall('filemanager', 'chmod', array($dir, $name, $mode));
		exit(json_encode($r));
	}

	/**
	 * 打包为 zip（ajax）；打包成功后前端用 download 动作取回
	 */
	public function compress()
	{
		$dir = isset($_REQUEST['dir']) ? trim($_REQUEST['dir']) : '/';
		$names = isset($_REQUEST['names']) ? $_REQUEST['names'] : array();

		if (!is_array($names)) {
			$names = array();
		}

		$zipName = isset($_REQUEST['zipName']) ? trim($_REQUEST['zipName']) : '';
		$r = apicall('filemanager', 'compress', array($dir, $names, $zipName));
		exit(json_encode($r));
	}

	/**
	 * 解压 zip（ajax，GRACE 档：解压可覆盖既有文件）
	 */
	public function extract()
	{
		$dir = isset($_REQUEST['dir']) ? trim($_REQUEST['dir']) : '/';
		$name = isset($_REQUEST['name']) ? trim($_REQUEST['name']) : '';
		$dest = isset($_REQUEST['dest']) ? trim($_REQUEST['dest']) : '';
		$r = apicall('filemanager', 'extract', array($dir, $name, $dest));
		exit(json_encode($r));
	}
}
?>
