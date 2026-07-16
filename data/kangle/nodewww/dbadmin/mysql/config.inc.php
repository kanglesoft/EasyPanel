<?php
/**
 * phpMyAdmin configuration for EasyPanel Docker environment
 * Auto-generated during UI refactor deployment.
 */

/* Blowfish secret for cookie auth (must be 32 chars for phpMyAdmin 5.x) */
$cfg['blowfish_secret'] = 'EasyPanel-Docker-kangle-phpMyAdmin-Secret';

/* Servers configuration */
$i = 0;

/* First server: MySQL 8 container */
$i++;
$cfg['Servers'][$i]['auth_type'] = 'cookie';
$cfg['Servers'][$i]['host'] = 'mysql';
$cfg['Servers'][$i]['port'] = '3306';
$cfg['Servers'][$i]['connect_type'] = 'tcp';
$cfg['Servers'][$i]['compress'] = false;
$cfg['Servers'][$i]['extension'] = 'mysqli';
$cfg['Servers'][$i]['AllowNoPassword'] = false;
$cfg['Servers'][$i]['AllowRoot'] = true;

/* Directories for saving/loading files from server */
$cfg['UploadDir'] = '';
$cfg['SaveDir'] = '';

/* Session validity */
$cfg['LoginCookieValidity'] = 1440;
