<?php

declare(strict_types=1);

namespace FBPageAutopublisher;

class Plugin
{
    private static ?self $instance = null;

    private Admin_Settings $admin_settings;

    private Post_Metabox $post_metabox;

    private Publisher $publisher;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $template_parser = new Template_Parser();
        $logger = new Logger();
        $image_processor = new Image_Processor();
        $facebook_client = new Facebook_Client();

        $this->admin_settings = new Admin_Settings();
        $this->post_metabox = new Post_Metabox();
        $this->publisher = new Publisher($template_parser, $image_processor, $facebook_client, $logger);

        add_action('plugins_loaded', [$this, 'init']);
        register_activation_hook(FBAP_PLUGIN_FILE, [self::class, 'activate']);
    }

    public function init(): void
    {
        $this->admin_settings->hooks();
        $this->post_metabox->hooks();
        $this->publisher->hooks();
    }

    public static function activate(): void
    {
        $settings = get_option(OPTION_KEY);

        if (! is_array($settings)) {
            add_option(OPTION_KEY, get_default_settings());
            return;
        }

        update_option(OPTION_KEY, wp_parse_args($settings, get_default_settings()));
    }
}
