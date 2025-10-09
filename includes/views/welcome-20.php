<div class="wcct-welcome">
    <div class="icon-wrap">
        <img src="<?php echo esc_attr( WCCT_ASSETS ); ?>/images/screenshot.png" alt="Conversion Tracking Logo">
    </div>

    <button type="button" class="notice-dismiss" id="dismiss-wcct-20">
        <span class="screen-reader-text">Dismiss this notice.</span>
    </button>

    <div class="msg-wrap">
        <h3>Conversion Tracking for WooCommerce just got <span style="color:#CD47B1">better</span></h3>

        <p>
            Good news, now you can easily track conversions of your WooCommerce store and send data to your favorite ad platforms for improved and precised retargeting campaigns without any coding at all! This makes your Facebook, Twitter, Google Adwords marketing and retargeting easier than ever!
        </p>

        <p class="cta-buttons">
            <a href="https://wedevs.com/in/woocommerce-conversion-tracking" target="_blank" class="btn-whats-new">Checkout What's New</a>
            <a href="https://wedevs.com/woocommerce-conversion-tracking/upgrade-to-pro/?utm_source=wp-admin&utm_medium=pro-upgrade&utm_campaign=wcct_upgrade&utm_content=Get_Premium" target="_blank" class="btn-pro-upgrade">Upgrate to Pro</a>
        </p>

        <span class="bottom-logo">
            <img src="<?php echo esc_attr( WCCT_ASSETS ); ?>/images/logo-full.png" width="125" alt="Conversion Tracking Logo">
        </span>
    </div>
</div>

<script type="text/javascript">
    jQuery(function($) {
        $('#dismiss-wcct-20').on('click', function(event) {
            event.preventDefault();

            $(this).parents('.wcct-welcome').slideUp('fast', function() {
                $(this).remove();
            });

            wp.ajax.send('wcct_dismiss_notice');
        });
    });
</script>

<style>
.wcct-welcome {
    background: #fff;
    padding: 10px 10px 0 10px;
    border: 1px solid #e5e5e5;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    display: flex;
    margin-top: 15px;
    margin-bottom: 15px;
    position: relative;
}

.wcct-welcome .notice-dismiss {
    z-index: 99;
}

.wcct-welcome .icon-wrap {
    width: 242px;
    margin-right: 15px;
}

.wcct-welcome .msg-wrap {
    width: calc(100% - 242px);
    padding-top: 10px;
    position: relative;
}

.wcct-welcome .icon-wrap img {
    max-width: 100%;
}

a.btn-whats-new,
a.btn-pro-upgrade {
    font-size: 14px;
    line-height: 28px;
    height: 32px;
    display: inline-block;
    color: #fff;
    text-decoration: none;
    cursor: pointer;
    border-radius: 3px;
    white-space: nowrap;
    box-sizing: border-box;
    padding: 1px 14px 0px;
}

a.btn-whats-new:hover {
    color: #fff;
    background: #e45f44;
}

a.btn-whats-new {
    background-color: #FD6E51;
    margin-right: 10px;
    border: 1px solid #f55d3e;
}

a.btn-pro-upgrade {
    color: #B190E6;
    background-color: #fff;
    border: 1px solid #ddd;
    box-shadow: 0px 3px 10px 0px rgba(0,0,0, 0.1);
}

a.btn-pro-upgrade:hover {
    background-color: #B190E6;
    color: #fff;
    border: 1px solid #B190E6;
}

.msg-wrap .bottom-logo {
    position: absolute;
    bottom: 15px;
    right: 20px;
}

.wcct-welcome h3 {
    color: #000;
    margin-top: 5px;
    margin-bottom: 10px;
    font-weight: bold;
    font-size: 20px;
    line-height: 150%;
}

p.cta-buttons {
    margin: 18px 0 0 0;
}

@media (min-width: 768px) {
    p.cta-buttons {
        margin-bottom: 20px;
    }
}

@media (max-width: 767px) {
    .wcct-welcome {
        display: block;
        padding-bottom: 20px;
    }

    .wcct-welcome .icon-wrap,
    .wcct-welcome .msg-wrap {
        width: 100%;
    }

    .wcct-welcome .msg-wrap {
        padding: 0 15px;
    }

    .msg-wrap .bottom-logo {
        display: none;
    }
}
</style>