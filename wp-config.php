<?php
/**
 * WordPress基础配置文件。
 *
 * 这个文件被安装程序用于自动生成wp-config.php配置文件，
 * 您可以不使用网站，您需要手动复制这个文件，
 * 并重命名为“wp-config.php”，然后填入相关信息。
 *
 * 本文件包含以下配置选项：
 *
 * * MySQL设置
 * * 密钥
 * * 数据库表名前缀
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** MySQL 设置 - 已适配SAE,无需修改 ** //
/** WordPress 数据库的名称 */
define('DB_NAME', SAE_MYSQL_DB);

/** MySQL 数据库用户名 */
define('DB_USER', SAE_MYSQL_USER);

/** MySQL 数据库密码 */
define('DB_PASSWORD', SAE_MYSQL_PASS);

/** MySQL 主机 */
define('DB_HOST', SAE_MYSQL_HOST_M.':'.SAE_MYSQL_PORT);

/** 创建数据表时默认的文字编码 */
define( 'DB_CHARSET', 'utf8mb4' );

/** 数据库整理类型。如不确定请勿更改 */
define( 'DB_COLLATE', '' );

/**#@+
 * 身份认证密钥与盐。
 *
 * 修改为任意独一无二的字串！
 * 或者直接访问{@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org密钥生成服务}
 * 任何修改都会导致所有cookies失效，所有用户将必须重新登录。
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'F|:}/qw;5{kH31KQO[#}/J}0h~BiXSW`&$dXm[T$j-R].b&/8Tb~[,M~255CkT{v' );
define( 'SECURE_AUTH_KEY',  'f7w@!+N3@>83y@:wcr}GmS!z`V_-*u6)tErU(Yit5&8Wrr.zFOE.2Xd}U~4Y)#On' );
define( 'LOGGED_IN_KEY',    'jw]]rUQ)cduET53 >d?+*q0-E009q`.(2{N_$s,ck^KpT38EtznuHz_f2/ Z4c?:' );
define( 'NONCE_KEY',        'ef-%N#1,#s!Ma0P&xmgNeS#)4gU0vtI!T/I!Ojp.XM^}Kl7LIV{}>Z=[<IHOPo}+' );
define( 'AUTH_SALT',        'h8N4 kg%$qhj,6TY%A)mvjBU=:2e{?.r!szaQVMfX3[E &4_!h0E!v42Xyk0uwn_' );
define( 'SECURE_AUTH_SALT', ',nV=<?[BdnZJm2^lHqF,$7VKZC{3GE-k6R`HAqg&t!(!sRv,/hui&;1}0Akw31$/' );
define( 'LOGGED_IN_SALT',   'tmg;l|9KvgTBQk$_IS6ty}j`_t(]:1NL`.Hsj ~@g]L:#hgQN@~tfW0@O=F.(3%[' );
define( 'NONCE_SALT',       'OHr$aZZ5(+Ak#X*V1plA3Fga/(m^/Z_ Gt[3j|_U9`-vNa!pFZ_7qN1gIQcG[rJS' );

/**#@-*/

/**
 * WordPress数据表前缀。
 *
 * 如果您有在同一数据库内安装多个WordPress的需求，请为每个WordPress设置
 * 不同的数据表前缀。前缀名只能为数字、字母加下划线。
 */
$table_prefix = 'wp_';

/**
 * 开发者专用：WordPress调试模式。
 *
 * 将这个值改为true，WordPress将显示所有用于开发的提示。
 * 强烈建议插件开发者在开发环境中启用WP_DEBUG。
 *
 * 要获取其他能用于调试的信息，请访问文档。
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
define('WP_DEBUG', false);
ini_set('display_errors','Off');
ini_set('error_reporting', E_ALL );
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);

//define('UPLOADS',substr(SAE_TMP_PATH,0,strlen(SAE_TMP_PATH)-1));

// ** 文件上传设置, 可根据需要修改 ** //
/** SAE Storage Domain名称 */
define('SAE_STORAGE', 'wordpress');

/** 文件上传路径 */ 
define('SAE_DIR','/uploads');
define('WP_CACHE', false);


/* 好了！请不要再继续编辑。请保存本文件。使用愉快！ */

/** WordPress目录的绝对路径。 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __FILE__ ) . '/' );
}

/** 设置WordPress变量和包含文件。 */
require_once( ABSPATH . 'wp-settings.php' );
