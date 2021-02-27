<?php
/**
 * 因为默认保留了upload_information 参数，新版本直接以默认值带入'' ，而旧版本则会使用保留过的数据来改动，因此先保留这个写法
 */
if(!defined('WP_UNINSTALL_PLUGIN')){
	// 如果 uninstall 不是从 WordPress 调用，则退出
	exit();
}

// 恢复初始值
$wposs_options = get_option('wposs_options');
//
update_option('upload_path', $wposs_options['upload_information']['original']['upload_path']);
update_option('upload_url_path', $wposs_options['upload_information']['original']['upload_url_path']);

// 从 options 表删除选项
delete_option( 'wposs_options' );

// 删除其他额外的选项和自定义表
