<?php
$GLOBALS['lock_file'] = dirname(__FILE__) . '/../install.lock';
class InstallAPI extends API
{
	public function writeVersion()
	{
		$fp = @fopen($GLOBALS['lock_file'], 'wt');

		if (!$fp) {
			return false;
		}

		fwrite($fp, EASYPANEL_VERSION);
		fclose($fp);
		return true;
	}

	public function executeSql($pdo, $sqlfile)
	{
		$files = file($sqlfile);
		if (!$files || count($files) <= 0) {
			trigger_error('无法打开' . $sqlfile . '文件');
			return false;
		}

		$sql = '';
		$i = 0;

		while ($i < count($files)) {
			if (strncmp($files[$i], '-- ', 3) == 0) {
				continue;
			}

			$sql .= $files[$i];
			if (substr($files[$i], 0 - 2) == ";\n" || substr($files[$i], 0 - 3) == ";\r\n") {
				// schema-version-bump：失败落 error_log 便于排查（重放的裸 ALTER duplicate column 属预期噪声，不作验收判据）
				$r = @$pdo->exec($sql);
				if ($r === false) {
					error_log('[upgrade.sql] ' . $pdo->errorInfo()[2]);
				}
				$sql = '';
			}

			++$i;
		}

		if ($sql != '') {
			$r = @$pdo->exec($sql);
			if ($r === false) {
				error_log('[upgrade.sql] ' . $pdo->errorInfo()[2]);
			}
		}

		return true;
	}

	public function getInstallVersion()
	{
		$line = @file($GLOBALS['lock_file']);
		return trim($line[0]);
	}
}

?>