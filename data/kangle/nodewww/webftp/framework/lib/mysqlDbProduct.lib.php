<?php
class MysqlDbProduct extends DbProduct
{
	public function connect($node)
	{
		$db_host = $node['db_host'];

		if ($db_host == '') {
			$db_host = 'localhost';
		}

		$dsn = 'mysql:host=' . $db_host;

		if ($node['db_port']) {
			$dsn .= ';port=' . $node['db_port'];
		}

		try {
			$this->pdo = new PDO($dsn, $node['db_user'], $node['db_passwd'], array(PDO::ATTR_TIMEOUT => 10));
		}
		catch (Exception $e) {
			return false;
		}

		return true;
	}

	public function create($vhost)
	{
		$user = $vhost['db_name'];
		$passwd = $vhost['passwd'];
		if ($user == 'mysql' || $user == 'root') {
			return false;
		}

		$sqls = array('CREATE USER \'' . $user . '\'@\'%\' IDENTIFIED BY \'' . $passwd . '\'', 'CREATE DATABASE IF NOT EXISTS `' . $user . '`', 'GRANT ALL PRIVILEGES ON `' . $user . '` . * TO \'' . $user . '\'@\'%\'');
		return $this->query($sqls);
	}

	public function change_quota($vhost)
	{
		return true;
	}

	public function delTestDatabase()
	{
		$sqls = array('DROP DATABASE `test`');
		return $this->query($sqls);
	}

	public function remove($uid)
	{
		$user = $uid;
		if ($user == 'mysql' || $user == 'root') {
			return false;
		}

		$sqls = array('DROP USER `' . $user . '`@\'%\'', 'DROP DATABASE `' . $user . '`');
		return $this->query($sqls);
	}

	public function password($uid, $passwd)
	{
		$user = $uid;
		if ($user == 'mysql' || $user == 'root') {
			return false;
		}

		return $this->query(array('SET PASSWORD FOR \'' . $user . '\'@\'%\' = PASSWORD( \'' . $passwd . '\' )'));
	}

	/**
	 * 为 phpMyAdmin 单点登录创建/授权专用账号，并授予指定 vhost 数据库的完整权限。
	 * 由 vhost 面板 dbadmin() 调用（已通过节点特权账号连接），幂等可重复执行。
	 *
	 * @param string $dbName   vhost 数据库名
	 * @param string $pmaUser  SSO 账号名
	 * @param string $pmaPass  SSO 账号密码
	 * @return bool
	 */
	public function grantPma($dbName, $pmaUser, $pmaPass)
	{
		if (!$this->pdo) {
			return false;
		}
		if ($dbName == 'mysql' || $dbName == 'root' || $dbName == '') {
			return false;
		}
		if ($pmaUser == '' || $pmaPass == '') {
			return false;
		}

		$sqls = array(
			"CREATE DATABASE IF NOT EXISTS `" . $dbName . "`",
			"CREATE USER IF NOT EXISTS '" . $pmaUser . "'@'%' IDENTIFIED BY '" . $pmaPass . "'",
			"GRANT ALL PRIVILEGES ON `" . $dbName . "`.* TO '" . $pmaUser . "'@'%'",
			'FLUSH PRIVILEGES',
		);
		return $this->query($sqls);
	}

	public function used($uid, $failedreturnfalse = false)
	{
		$user = $uid;
		if ($user == 'mysql' || $user == 'root') {
			return false;
		}

		$sql = 'SELECT sum(Data_length ) + sum( Index_length ) FROM information_schema.`TABLES` WHERE TABLE_SCHEMA = \'' . $user . '\'';
		$result = $this->pdo->query($sql);

		if (!$result) {
			if ($failedreturnfalse) {
				return false;
			}

			return 0;
		}

		$row = $result->fetch();
		return $row[0] / 1048576;
	}

	private function query(array $sqls)
	{
		$i = 0;

		while ($i < count($sqls)) {
			$result = $this->pdo->exec($sqls[$i]);
			if (!$result && $this->pdo->errorCode() != '00000') {
				return false;
			}

			++$i;
		}

		return true;
	}

	public function dumpOutPassword($user)
	{
		$sql = 'SELECT Password FROM user WHERE User=\'' . $user . '\' LIMIT 1';
		$this->pdo->exec('USE mysql');
		$result = $this->pdo->query($sql);
		$ret = $result->fetch(PDO::FETCH_ASSOC);
		return $ret['Password'];
	}

	public function dumpInPassword($user, $passwd)
	{
		if ($user == 'mysql' || $user == 'root') {
			return false;
		}

		return $this->query(array('SET PASSWORD FOR \'' . $user . '\'@\'%\' = \'' . $passwd . '\''));
	}

	public function getAllUsed()
	{
		$sql = 'SELECT TABLE_SCHEMA as name,sum(Data_length ) + sum( Index_length ) as size FROM information_schema.`TABLES` group by TABLE_SCHEMA';
		$result = $this->pdo->query($sql);

		if (!$result) {
			return false;
		}

		return $result->fetchAll();
	}
}

?>