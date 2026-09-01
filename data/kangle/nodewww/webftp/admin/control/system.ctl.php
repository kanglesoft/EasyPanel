<?php
needRole('admin');
class SystemControl extends Control
{
	/**
	 * 系统设置控制器
	 *
	 * 历史清理（B2 / B3，2026-08-30）：
	 *   - setPhpiniFrom() 引用 system/phpini.html，但该模板从未存在，访问即抛模板异常（B2）。
	 *     其本意（PHP ini 在线编辑）在容器内无安全价值，已移除。
	 *   - editFileForm() / editFile() 早已 exit('not suppor')，且直接写宿主文件系统，
	 *     属废弃死代码（B3），连同其唯一的消费模板 system/file.html 一并清理。
	 *   - is_utf8() 仅被 editFile() 使用，随 editFile() 一同移除。
	 *
	 * 保留本文件以便将来按需补充真正可用的系统级设置动作；当前无对外 action。
	 */
}
?>
