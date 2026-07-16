<?php
/**
 * acme.sh 证书自动部署脚本
 *
 * 遍历 /root/.acme.sh 下所有证书，把每个证书同步到绑定该域名的 vhost 站点目录，
 * 并更新 vhost 表的 certificate / certificate_key / port 字段，最后 reload kangle。
 *
 * 推荐通过 crond 每天调用一次：
 *   /vhs/kangle/bin/renew_ssl.sh
 */
if (php_sapi_name() !== 'cli') {
    exit('CLI only');
}

define('SYS_ROOT', '/vhs/kangle/nodewww/webftp/framework');
require_once SYS_ROOT . '/runtime.php';

$acmeHome = '/root/.acme.sh';
$changed = false;

if (!is_dir($acmeHome)) {
    echo "[apply_all_acme_certs] acme.sh home not found: {$acmeHome}\n";
    exit(0);
}

$dh = opendir($acmeHome);
if (!$dh) {
    echo "[apply_all_acme_certs] cannot open acme home\n";
    exit(1);
}

function getDomainFromConf($confFile)
{
    $conf = @parse_ini_file($confFile);
    if (!empty($conf['Le_Domain'])) {
        return $conf['Le_Domain'];
    }
    return basename(dirname($confFile));
}

function findVhostByDomain($domain)
{
    $domain = strtolower(trim($domain));
    $vhosts = daocall('vhost', 'listVhostNotcdn', array());
    if (empty($vhosts) || !is_array($vhosts)) {
        return null;
    }
    foreach ($vhosts as $v) {
        $domains = daocall('vhostinfo', 'getDomain', array($v['name']));
        if (is_array($domains)) {
            foreach ($domains as $d) {
                if (strtolower($d['name']) === $domain) {
                    return $v;
                }
            }
        }
    }
    return null;
}

function ensureDocRoot($docRoot, $uid, $gid)
{
    $uid = intval($uid);
    $gid = intval($gid);
    if (!is_dir($docRoot)) {
        if (!@mkdir($docRoot, 0750, true)) {
            return false;
        }
    }
    @chown($docRoot, $uid);
    @chgrp($docRoot, $gid);
    return true;
}

while (($entry = readdir($dh)) !== false) {
    if ($entry === '.' || $entry === '..' || $entry === 'account.conf' || $entry === 'ca') {
        continue;
    }
    $domainDir = $acmeHome . '/' . $entry;
    if (!is_dir($domainDir)) {
        continue;
    }
    $confFile = $domainDir . '/' . $entry . '.conf';
    $crtFile  = $domainDir . '/fullchain.cer';
    $keyFile  = $domainDir . '/' . $entry . '.key';
    if (!file_exists($confFile) || !file_exists($crtFile) || !file_exists($keyFile)) {
        continue;
    }

    $domain = getDomainFromConf($confFile);
    $user = findVhostByDomain($domain);
    if (empty($user) || empty($user['doc_root'])) {
        echo "[apply_all_acme_certs] skip {$domain}: no matching vhost\n";
        continue;
    }

    $certificate = @file_get_contents($crtFile);
    $certificate_key = @file_get_contents($keyFile);
    if (!$certificate || !$certificate_key) {
        echo "[apply_all_acme_certs] skip {$domain}: cannot read cert/key\n";
        continue;
    }

    $docRoot = rtrim($user['doc_root'], '/');
    if (!ensureDocRoot($docRoot, $user['uid'], $user['gid'])) {
        echo "[apply_all_acme_certs] skip {$domain}: cannot ensure doc_root\n";
        continue;
    }

    $crt_target = $docRoot . '/ssl.crt';
    $key_target = $docRoot . '/ssl.key';

    // 清理旧文件（避免 root 属主导致无法覆盖）
    if (is_file($crt_target) || is_link($crt_target)) @unlink($crt_target);
    if (is_file($key_target) || is_link($key_target)) @unlink($key_target);

    if (false === file_put_contents($crt_target, $certificate)) {
        echo "[apply_all_acme_certs] skip {$domain}: write ssl.crt failed\n";
        continue;
    }
    if (false === file_put_contents($key_target, $certificate_key)) {
        echo "[apply_all_acme_certs] skip {$domain}: write ssl.key failed\n";
        continue;
    }

    @chown($crt_target, intval($user['uid']));
    @chgrp($crt_target, intval($user['gid']));
    @chown($key_target, intval($user['uid']));
    @chgrp($key_target, intval($user['gid']));

    $arr = array('certificate' => 'ssl.crt', 'certificate_key' => 'ssl.key');
    $port = isset($user['port']) ? trim($user['port']) : '';
    if ($port === '') {
        $arr['port'] = '80,443s';
    } elseif (strpos($port, '443s') === false) {
        $arr['port'] = $port . ',443s';
    }
    daocall('vhost', 'updateVhost', array($user['name'], $arr));

    echo "[apply_all_acme_certs] applied {$domain} -> {$user['name']}\n";
    $changed = true;
}
closedir($dh);

if ($changed) {
    echo "[apply_all_acme_certs] reloading kangle...\n";
    exec('/vhs/kangle/bin/sync_all_vhost.sh >/dev/null 2>&1');
} else {
    echo "[apply_all_acme_certs] no cert changed\n";
}

exit(0);
