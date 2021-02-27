<?php
/**
Plugin Name: WPOSS(阿里云对象存储)
Plugin URI: https://www.laobuluo.com/2250.html
Description: WordPress同步附件内容远程至阿里云OSS对象存储中，实现网站数据与静态资源分离，提高网站加载速度。微信公众号：  <font color="red">站长事儿</font>
Version: 4.4
Author: 老部落
Author URI: https://www.laobuluo.com
 */
if (!defined('ABSPATH')) die();

use WPOSS\Api;

if (!class_exists('WPOSS')) {
    class WPOSS {

        private $option_name     = 'wposs_options';               // 插件参数保存名称
        private $menu_title      = 'WPOSS设置';                    // 设置菜单的菜单名
        private $page_title      = 'WPOSS设置';                    // 设置菜单的页面title
        private $capability      = 'manage_options';              // 设置页面管理所需权限
        private $version         = '4.2';                         // 插件数据版本， 每次修改应与上方的Version值相同
        private $setting_notices = [
                    'update_success' => '设置已保存',              // post数据保存成功时提示内容
                    'update_failed'  => '插件设置更新失败',  // 失败时提示
                ];
        private $image_display_default_value = 'image/auto-orient,1/quality,q_90/format,webp';  // 数据万象默认规则
        private $image_display_default_tab   = '?x-oss-process=';                                               // 万象规则url连字符

        private $base_folder;
        private $wp_upload_dir;
        private $object_storage;
        private $options;

        function __construct() {
            $this->includes();
            $this->constants();

            # 插件 activation 函数当一个插件在 WordPress 中”activated(启用)”时被触发。
            register_activation_hook(__FILE__, array($this, 'init_options'));
            register_deactivation_hook(__FILE__, array($this, 'restore_options'));  # 禁用时触发钩子

            # 避免上传插件/主题被同步到对象存储
            if (substr_count($_SERVER['REQUEST_URI'], '/update.php') <= 0) {
                add_filter('wp_handle_upload', array($this, 'upload_attachments'));
                if ( version_compare(get_bloginfo('version'), 5.3, '<') ){
                    add_filter( 'wp_update_attachment_metadata', array($this, 'upload_and_thumbs') );
                } else {
                    add_filter( 'wp_generate_attachment_metadata', array($this, 'upload_and_thumbs') );
                    add_filter( 'wp_save_image_editor_file', array($this, 'save_image_editor_file') );
                }
            }

            # 检测不重复的文件名
            add_filter('wp_unique_filename', array($this, 'unique_filename') );

            # 删除文件时触发删除远端文件，该删除会默认删除缩略图
            add_action('delete_attachment', array($this, 'delete_remote_attachment'));

            # 添加插件设置菜单
            add_action('admin_menu', array($this, 'admin_menu_setting'));
            add_filter('plugin_action_links', array($this, 'setting_plugin_action_links'), 10, 2);
            # 自动重命名
            add_filter( 'sanitize_file_name', array($this, 'sanitize_file_name_handler'), 10, 1 );
            # 图片显示处理
            add_filter( 'the_content', array($this, 'image_display_processing') );
        }

        private function includes() {
            require_once('api.php');
        }

        private function constants() {
            $this->base_folder = plugin_basename(dirname(__FILE__));
            $this->wp_upload_dir = wp_get_upload_dir();
            $this->options = get_option($this->option_name);
            $this->object_storage = new Api($this->options);  // option更新后，若变动了参数，则Api实例的重新创建，目前只有setting中会触发
        }

        /**
         * 文件上传功能基础函数，被其它需要进行文件上传的模块调用
         * @param $key  : 远端需要的Key值[包含路径]
         * @param $file_local_path : 文件在本地的路径。
         *
         * @return bool  : 暂未想好如何与wp进行响应。

         */
        public function _file_upload($key, $file_local_path) {
            ### 上传文件
            # 由于增加了独立文件名钩子对cos中同名文件的判断，避免同名文件的存在，因此这里直接覆盖上传。
            try {
                $this->object_storage->Upload(
                    $this->key_handler($key, get_option('upload_url_path')),
                    $file_local_path
                );
                // 如果上传成功，且不再本地保存，在此删除本地文件
                if ($this->options['no_local_file']) {
                    $this->delete_local_file($file_local_path);
                }
                return True;
            } catch (\Exception $e) {
                return False;
            }
        }

        private function remote_key_exist( $filename ) {
            return $this->object_storage->hasExist( $this->key_handler($this->wp_upload_dir['subdir'] . "/$filename",
                get_option('upload_url_path')));
        }

        /**
         * 删除远程附件（包括图片的原图）
         *   这里全部以非/开头，因此上传的函数中也要替换掉key中开头的/
         * @param $post_id
         */
        public function delete_remote_attachment($post_id) {
            // 获取要删除的对象Key的数组
            $deleteObjects = array();
            $meta = wp_get_attachment_metadata( $post_id );
            $upload_url_path = get_option('upload_url_path');

            if (isset($meta['file'])) {
                $attachment_key = $meta['file'];
                array_push($deleteObjects, $this->key_handler($attachment_key, $upload_url_path));
            } else {
                $file = get_attached_file( $post_id );
                $attached_key = str_replace( $this->wp_upload_dir['basedir'] . '/', '', $file );  # 不能以/开头
                $deleteObjects[] = $this->key_handler($attached_key, $upload_url_path);
            }

            if (isset($meta['sizes']) && count($meta['sizes']) > 0) {
                foreach ($meta['sizes'] as $val) {
                    $attachment_thumbs_key = dirname($meta['file']) . '/' . $val['file'];
                    $deleteObjects[] = $this->key_handler($attachment_thumbs_key, $upload_url_path);
                }
            }

            if ( !empty( $deleteObjects ) ) {
                // 执行删除远程对象
                $allKeys = array_chunk($deleteObjects, 1000);  # 每次最多删除1000个，多于1000循环进行
                foreach ($allKeys as $keys){
                    //删除文件, 每个数组1000个元素
                    $this->object_storage->Delete($keys);
                }
            }
        }

        // 初始化选项
        // TODO: 让不同对象存储适用相同参数与setting
        public function init_options() {
            $options = array(
                'version' => $this->version,  # 用于以后当有数据结构升级时初始化数据
                'bucket' => "",
                'endpoint' => "",
                'accessKeyId' => "",
                'accessKeySecret' => "",
                'no_local_file' => False,     # 不在本地保留备份
                'backup_url_path' => '',
                'cname' => False,             # true为开启CNAME。CNAME是指将自定义域名绑定到存储空间上。可以用来代替ENDPOINT
                'upload_information' => array(
                    'original' => array(
                        'upload_path' => '',
                        'upload_url_path' => '',
                    ),
                    'active' => array(
                        'upload_path' => '',
                        'upload_url_path' => '',
                    ),
                ),
                'opt' => array(
                    'auto_rename' => False,
                    'img_process' => array(
                        'switch' => False,
                        'style_value' => '',
                    ),
                ),
            );

            if(!$this->options){
                if (add_option($this->option_name, $options, '', 'yes')) {
                    $this->options = get_option($this->option_name);
                }
            }

            if ( isset($this->options['backup_url_path']) && $this->options['backup_url_path'] != '' ) {
                update_option('upload_url_path', $this->options['backup_url_path']);
                // 理论上来说，更新完upload_url_path后，这里的option的backup_url_path还需要修改为'';
                // 但因为时机上目前只有激活与禁用2种，因此就由禁用时直接赋值，这里减少一次更新。
                // 后续出现多种场景判断再考虑。
            }
        }

        public function restore_options () {
            $this->options['backup_url_path'] = get_option('upload_url_path');
            if (update_option($this->option_name, $this->options)) {  // 此处修改的参数不影响对象存储实例
                $this->options = get_option($this->option_name);      // 上面的赋值及更新，这里似乎不用再重新获取。 - -!
            }
            update_option('upload_url_path', '');
        }

        /**
         * 此函数处理上传的key，用于支持 对象存储子目录
         * @param $key
         * @param $upload_url_path
         * @return string
         */
        private function key_handler($key, $upload_url_path){
            # 参数2 为了减少option的获取次数
            $url_parse = wp_parse_url($upload_url_path);
            # 约定url不要以/结尾，减少判断条件
            if (array_key_exists('path', $url_parse)) {
                if ( substr($key, 0, 1) == '/' ) {
                    $key = $url_parse['path'] . $key;
                } else {
                    $key = $url_parse['path'] . '/' . $key;
                }
            }
            # $url_parse['path'] 以/开头，在七牛环境下不能以/开头，所以需要处理掉
            return ltrim($key, '/');
        }

        /**
         * 删除本地文件
         * @param $file_path : 文件路径
         * @return bool
         */
        public function delete_local_file($file_path) {
            try {
                if (!@file_exists($file_path)) {  # 文件不存在
                    return TRUE;
                }
                if (!@unlink($file_path)) { # 删除文件
                    return FALSE;
                }
                return TRUE;
            } catch (Exception $ex) {
                return FALSE;
            }
        }

        /**
         * 上传图片及缩略图
         * @param $metadata: 附件元数据
         * @return array $metadata: 附件元数据
         * 官方的钩子文档上写了可以添加 $attachment_id 参数，但实际测试过程中部分wp接收到不存在的参数时会报错，上传失败，返回报错为“HTTP错误”
         */
        public function upload_and_thumbs( $metadata ) {
            if (isset( $metadata['file'] )) {
                # 1.先上传主图
                $attachment_key = $metadata['file'];  // 远程key路径, 此路径不是以/开头
                $attachment_local_path = $this->wp_upload_dir['basedir'] . '/' . $attachment_key;  # 在本地的存储路径
                $this->_file_upload($attachment_key, $attachment_local_path);  # 调用上传函数
            }

            # 如果存在缩略图则上传缩略图
            if (isset($metadata['sizes']) && count($metadata['sizes']) > 0) {
                foreach ($metadata['sizes'] as $val) {
                    $attachment_thumbs_key = dirname($metadata['file']) . '/' . $val['file'];  // 生成object 的 key
                    $attachment_thumbs_local_path = $this->wp_upload_dir['basedir'] . '/' . $attachment_thumbs_key;  // 本地存储路径
                    $this->_file_upload($attachment_thumbs_key, $attachment_thumbs_local_path);  //调用上传函数
                }
            }

            return $metadata;
        }

        /**
         * @param array  $upload {
         *     Array of upload data.
         *
         *     @type string $file Filename of the newly-uploaded file.
         *     @type string $url  URL of the uploaded file.
         *     @type string $type File type.
         * @return array  $upload
         */
        public function upload_attachments ($upload) {
            $mime_types       = get_allowed_mime_types();
            $image_mime_types = array(
                // Image formats.
                $mime_types['jpg|jpeg|jpe'],
                $mime_types['gif'],
                $mime_types['png'],
                $mime_types['bmp'],
                $mime_types['tiff|tif'],
                $mime_types['ico'],
            );
            if ( ! in_array( $upload['type'], $image_mime_types ) ) {
                $key        = str_replace( $this->wp_upload_dir['basedir'] . '/', '', $upload['file'] );
                $local_path = $upload['file'];
                $this->_file_upload( $key, $local_path);
            }

            return $upload;
        }

        public function save_image_editor_file($override){
            add_filter( 'wp_update_attachment_metadata', array($this,'image_editor_file_save' ));
            return $override;
        }

        public function image_editor_file_save( $metadata ){
            $metadata = $this->upload_and_thumbs($metadata);
            remove_filter( 'wp_update_attachment_metadata', array($this, 'image_editor_file_save') );
            return $metadata;
        }

        /**
         * Filters the result when generating a unique file name.
         *
         * @since 4.5.0
         *
         * @param string        $filename                 Unique file name.

         * @return string New filename, if given wasn't unique
         *
         * 参数 $ext 在官方钩子文档中可以使用，部分 WP 版本因为多了这个参数就会报错。 返回“HTTP错误”
         */
        public function unique_filename( $filename ) {
            $ext = '.' . pathinfo( $filename, PATHINFO_EXTENSION );
            $number = '';

            while ( $this->remote_key_exist( $filename ) ) {
                $new_number = (int) $number + 1;
                if ( '' == "$number$ext" ) {
                    $filename = "$filename-" . $new_number;
                } else {
                    $filename = str_replace( array( "-$number$ext", "$number$ext" ), '-' . $new_number . $ext, $filename );
                }
                $number = $new_number;
            }
            return $filename;
        }

        public function sanitize_file_name_handler( $filename ){
            if ($this->options['opt']['auto_rename']) {
                return date("YmdHis") . "" . mt_rand(100, 999) . "." . pathinfo($filename, PATHINFO_EXTENSION);
            } else {
                return $filename;
            }
        }

        /** 根据提交数据进行缩略图设置修改与备份。 (暂时取消在这一步对插件参数更新的步骤，留到后面一起进行更新)
         * @param $options
         * @param $set_thumb
         * @return mixed
         */
        private function set_thumbsize_handler($options, $set_thumb){
            if($set_thumb) {
                $options['opt']['thumbsize'] = array(
                    'thumbnail_size_w' => get_option('thumbnail_size_w'),
                    'thumbnail_size_h' => get_option('thumbnail_size_h'),
                    'medium_size_w'    => get_option('medium_size_w'),
                    'medium_size_h'    => get_option('medium_size_h'),
                    'large_size_w'     => get_option('large_size_w'),
                    'large_size_h'     => get_option('large_size_h'),
                    'medium_large_size_w' => get_option('medium_large_size_w'),
                    'medium_large_size_h' => get_option('medium_large_size_h'),
                );
                update_option('thumbnail_size_w', 0);
                update_option('thumbnail_size_h', 0);
                update_option('medium_size_w', 0);
                update_option('medium_size_h', 0);
                update_option('large_size_w', 0);
                update_option('large_size_h', 0);
                update_option('medium_large_size_w', 0);
                update_option('medium_large_size_h', 0);
            } else {
                if(isset($options['opt']['thumbsize'])) {
                    update_option('thumbnail_size_w', $options['opt']['thumbsize']['thumbnail_size_w']);
                    update_option('thumbnail_size_h', $options['opt']['thumbsize']['thumbnail_size_h']);
                    update_option('medium_size_w', $options['opt']['thumbsize']['medium_size_w']);
                    update_option('medium_size_h', $options['opt']['thumbsize']['medium_size_h']);
                    update_option('large_size_w', $options['opt']['thumbsize']['large_size_w']);
                    update_option('large_size_h', $options['opt']['thumbsize']['large_size_h']);
                    update_option('medium_large_size_w', $options['opt']['thumbsize']['medium_large_size_w']);
                    update_option('medium_large_size_h', $options['opt']['thumbsize']['medium_large_size_h']);
                    unset($options['opt']['thumbsize']);
                }
            }
            return $options;
        }

        private function legacy_data_replace() {
            if(in_array(get_option('upload_path'), ["", "wp-content/uploads"])){
                global $wpdb;
                $originalContent = home_url('/wp-content/uploads');
                $newContent = get_option('upload_url_path');

                # 文章内容文字/字符替换
                $result = $wpdb->query(
                    "UPDATE {$wpdb->prefix}posts SET `post_content` = REPLACE( `post_content`, '{$originalContent}', '{$newContent}');"
                );

                $this->options['opt']['legacy_data_replace'] = 1;  # 值为1 表示已完成替换
            } else {
                $this->options['opt']['legacy_data_replace'] = 2;  # 值为2 表示upload_path非初始默认值，无法替换，建议使用wpreplace插件替换
            }
            update_option($this->option_name, $this->options);  // 文字替换，参数变动不影响Api实例
            return $this->options;
        }

        public function image_display_processing($content){
            if ( isset($this->options['opt']['img_process'])
                && $this->options['opt']['img_process']['switch'] ) {
                $media_url = get_option('upload_url_path');
                $pattern = '#<img[\s\S]*?src\s*=\s*[\"|\'](.*?)[\"|\'][\s\S]*?>#ims';  // img匹配正则
                $content = preg_replace_callback(
                    $pattern,
                    function($matches) use ($media_url) {
                        if (strpos($matches[1], $media_url) === false) {
                            return $matches[0];
                        } else {
                            return str_replace(
                                $matches[1],
                                $matches[1] . $this->image_display_default_tab . $this->options['opt']['img_process']['style_value'],
                                $matches[0]);
                        }
                    },
                    $content);
            }
            return $content;
        }

        private function set_img_process_handle($options, $img_process){
            if( isset($img_process['img_process_switch']) ){
                $options['opt']['img_process']['switch'] = True;
                switch( sanitize_text_field(trim(stripslashes($img_process['img_process_style_choice']))) ){
                    case "0":
                        $options['opt']['img_process']['style_value'] = $this->image_display_default_value;
                        break;
                    case "1":
                        $options['opt']['img_process']['style_value'] = sanitize_text_field(trim(stripslashes($img_process['img_process_style_customize'])));
                        break;
                }
            } else {
                $options['opt']['img_process']['switch'] = False;
            }
            return $options;
        }

        // 在插件列表页添加设置按钮
        public function setting_plugin_action_links($links, $file) {
            if ($file == plugin_basename(dirname(__FILE__) . '/index.php')) {
                $links[] = '<a href="admin.php?page=' . $this->base_folder . '/index.php">设置</a>';
            }
            return $links;
        }

        // 在导航栏“设置”中添加条目
        public function admin_menu_setting() {
            add_options_page($this->page_title, $this->menu_title, $this->capability, __FILE__, array($this, 'setting_page'));
        }

        /**
         *  插件设置页面
         */
        public function setting_page() {
            // 如果当前用户权限不足
            if (!current_user_can( $this->capability )) wp_die('Insufficient privileges!');

            $this->options = get_option($this->option_name);
            if ($this->options && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce']) && !empty($_POST)) {
                if($_POST['type'] == 'info_set') {
                    $this->options['bucket'] = isset($_POST['bucket']) ? sanitize_text_field(trim(stripslashes($_POST['bucket']))) : '';
                    $this->options['endpoint'] = isset($_POST['endpoint']) ? sanitize_text_field(trim(stripslashes($_POST['endpoint']))) : '';
                    $this->options['accessKeyId'] = isset($_POST['accessKeyId']) ? sanitize_text_field(trim(stripslashes($_POST['accessKeyId']))) : '';
                    $this->options['accessKeySecret'] = isset($_POST['accessKeySecret']) ? sanitize_text_field(trim(stripslashes($_POST['accessKeySecret']))) : '';
                    $this->options['opt']['auto_rename'] = isset($_POST['auto_rename']);
                    $this->options['no_local_file'] = isset($_POST['no_local_file']);

                    $this->options = $this->set_img_process_handle($this->options, $_POST);  // 更新数据万象设置，返回options，但未调用update_option
                    $this->options = $this->set_thumbsize_handler($this->options, isset($_POST['disable_thumb']) );

                    update_option('upload_url_path', esc_url_raw(trim(stripslashes($_POST['upload_url_path']))));
                    update_option($this->option_name, $this->options);
                    $this->object_storage = new Api($this->options);
                    # 原本想做update_option判断，但内容不改变时返回值为0，会当作失败处理，从业务逻辑上不合理。
                    ?>
                        <div class="notice notice-success settings-error is-dismissible"><p><?php echo($this->setting_notices['update_success']); ?></p></div>
                    <?php

                } else if ($_POST['type'] == 'info_replace') {
                    $this->options = $this->legacy_data_replace();
                }
            }
            require_once('setting.php');
        }
    }

    global $WPOSS;
    $WPOSS = new WPOSS();
}
