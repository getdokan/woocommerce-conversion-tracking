<div class="wcct-two-column">

    <div class="settings-wrap">

        <h2><?php esc_html_e( 'Integration Settings', 'woocommerce-conversion-tracking' ); ?></h2>

        <form action="" method="POST" id="integration-form">
            <?php
            foreach ( $integrations as $int_key => $integration ) {
                $name            = $integration->get_name();
                $id              = $integration->get_id();
                $settings_fields = $integration->get_settings();
                $settings        = $integration->get_integration_settings();
                $active          = ( $integration->is_enabled() ) ? 'Deactivate' : 'Activate';
                $border          = ( isset( $integration->multiple ) ) ? 'wcct-border' : '';
                ?>
                <div class="integration-wrap">
                    <div class="integration-name">
                        <div class="gateway">
                           <img src="<?php echo esc_attr( plugins_url( 'assets/images/'. $id .'.png', WCCT_FILE ) )?>" alt="" class="doc-list-icon">

                            <h3 class="gateway-text"><?php echo esc_attr( $name ); ?></h3>

                            <label class="switch" title="" data-original-title="Make Inactive">
                                <input type="checkbox" class="toogle-seller" name="settings[<?php echo esc_attr( $id ); ?>][enabled]" id="integration-<?php echo esc_attr( $id ); ?>" data-id="<?php echo esc_attr( $id ); ?>" value="1" <?php checked( true, $integration->is_enabled() ); ?> >
                                <span class="slider round wcct-tooltip" data-id="<?php echo esc_attr( $id ); ?>">
                                    <span class="wcct-tooltiptext integration-tooltip"><?php echo esc_attr( $active ); ?> </span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div class="integration-settings" id="setting-<?php echo esc_attr( $id ); ?>">
                        <?php
                        if ( ! $settings_fields ) {
                            continue;
                        }
                        ?>
                        <div class="wc-ct-form-group <?php echo esc_attr( $border ) ?>">
                            <table class="form-table custom-table" style="display: inline;">
                                <?php foreach ( $settings_fields as $key => $field ) {?>
                                    <tr>
                                        <th>
                                            <label for="<?php echo esc_attr( $id . '-' . $field['label'] ) ; ?>"><?php echo esc_attr( $field['label'] ); ?></label>
                                        </th>
                                        <td>
                                            <?php
                                            $placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';

                                            switch ( $field['type'] ) {
                                                case 'text':
                                                    $value = isset( $settings[0][ $field['name'] ] ) ? $settings[0][ $field['name'] ] : '';
                                                    printf( '<input type="text" name="settings[%s][%d][%s]" placeholder="%s" value="%s" id="%s" required >', esc_attr( $id ),0, esc_attr( $field['name'] ), esc_attr( $placeholder ), esc_attr( $value ), esc_attr( $id ) . '-' . esc_attr( $field['name'] ) );
                                                    break;

                                                case 'textarea':
                                                    $value = isset( $settings[ $field['name'] ] ) ? $settings[ $field['name'] ] : '';
                                                    printf( '<textarea type="text" name="settings[%s][%s]" placeholder="%s" id="%s" cols="30" rows="3">%s</textarea>', esc_attr( $id ), esc_attr( $field['name'] ), esc_attr( $placeholder ), esc_attr( $id ) . '-' . esc_attr( $field['name'] ), esc_attr( $value ) );
                                                    break;

                                                case 'checkbox':
                                                    $value = isset( $settings[ $field['name'] ] ) ? $settings[ $field['name'] ] : 'off';
                                                    printf( '<label for="%5$s"><input type="checkbox" name="settings[%1$s][%2$s]" %3$s id="%5$s" value="on"> %4$s</label>', esc_attr( $id ), esc_attr( $field['name'] ), checked( 'on', $value, false ),esc_attr(  $field['description'] ), esc_attr( $id ) . '-' . esc_attr( $field['name'] ) );
                                                    break;

                                                case 'multicheck':
                                                    ?>
                                                    <div class="wc-ct-option">
                                                        <?php
                                                        foreach ( $field['options'] as $key => $option ) {
                                                            $field_name = $field['name'];

                                                            $checked = isset( $settings[0][ $field_name ][ $key ] ) ? 'on' : '';
                                                            $disabled = isset( $option['profeature'] ) ? 'disabled' : '';
                                                            $class  = isset( $option['profeature'] ) ? 'disabled-class' : '';
                                                            $feature = isset( $option['profeature'] ) ? ' (Pro-feature)' : '';
                                                            ?>
                                                                <label for="<?php echo esc_attr( $id . '-' . $key ); ?>" class="<?php echo esc_attr( $class )?>">
                                                                    <input type="checkbox" name="settings[<?php echo esc_attr( $id ); ?>][0][<?php echo esc_attr( $field_name ); ?>][<?php echo esc_attr( $key ); ?>]" <?php checked( 'on', $checked ); ?> id="<?php echo esc_attr( $id . '-' . $key ); ?>" class="event <?php echo esc_attr( $class );?>" <?php echo esc_attr( $disabled ) ?>>
                                                                <?php

                                                                    $label  = isset( $option['label'] ) ? $option['label'] : $option;
                                                                    echo wp_kses_post( $label . $feature );

                                                                    $input_box  = isset( $option['event_label_box'] ) ?: false;

                                                                    $name  = isset( $option['label_name'] ) ? $option['label_name'] : '';
                                                                    $placeholder = isset( $option['placeholder'] ) ? $option['placeholder'] : '';
                                                                    $value = isset( $settings[0][ $field_name ][ $name ] ) ? $settings[0][ $field_name ][ $name ] : '';

                                                                    if ( $input_box ) {
                                                                       ?>
                                                                        <div class="event-label-box">
                                                                        <input type="text" name="settings[<?php echo esc_attr( $id ); ?>][0][<?php echo esc_attr( $field_name ); ?>][<?php echo esc_attr( $name ); ?>]"  class="" placeholder="<?php echo esc_attr( $placeholder ); ?>" value="<?php echo esc_attr( $value ) ?>">
                                                                        <?php

                                                                        if ( isset( $option['help'] ) && ! empty( $option['help'] ) ) {
                                                                            echo '<p class="help">' . $option['help'] . '</p>';
                                                                        }
                                                                        ?>
                                                                        </div>
                                                                        <?php
                                                                    }
                                                                    ?>
                                                                <?php
                                                                ?>
                                                                </label>
                                                                <br>
                                                                <?php
                                                            }
                                                            ?>
                                                    </div>
                                                <?php
                                            }

                                            if ( isset( $field['help'] ) && ! empty( $field['help'] ) ) {
                                                echo '<p class="help">' . $field['help'] . '</p>';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </table>
                        </div>
                    <?php

                        do_action( 'wcct_integration_' . $id, $integration );
                    ?>
                    </div>
                </div>

            <?php
            }
            ?>
            <div class="submit-area">
                <?php wp_nonce_field( 'wcct-settings' ); ?>
                <input type="hidden" name="action" value="wcct_save_settings">

                <button class="button button-primary" id="wcct-submit"><?php esc_html_e( 'Save Changes', 'woocommerce-conversion-tracking' ); ?></button>
            </div>
        </form>
    </div>

    <div class="sidebar-wrap">
        <div class="premium-box box-green">
            <h3 class="wcct-doc-title"><?php esc_html_e( 'Documentation', 'woocommerce-conversion-tracking' ); ?></h3>

            <ul class="wcct-doc-list">
                <li><a href="https://wedevs.com/docs/woocommerce-conversion-tracking/getting-started/installation/" target="_blank"><img src="<?php echo esc_attr( plugins_url( 'assets/images/getting_started.png', WCCT_FILE ) ); ?>" alt="" class="doc-list-icon"><span><?php esc_html_e( 'Getting Started', 'woocommerce-conversion-tracking' ) ?></span></a></li>
                <li><a href="https://wedevs.com/docs/woocommerce-conversion-tracking/facebook/?utm_source=wp-admin&utm_medium=docs-link&utm_campaign=wcct_docs&utm_content=Facebook" target="_blank"><img src="<?php echo esc_attr( plugins_url( 'assets/images/facebook.png', WCCT_FILE ) ); ?>" alt="" class="doc-list-icon"><span><?php esc_html_e( 'Facebook', 'woocommerce-conversion-tracking' ) ?></span></a></li>
                <li><a href="https://wedevs.com/docs/woocommerce-conversion-tracking/twitter/?utm_source=wp-admin&utm_medium=docs-link&utm_campaign=wcct_docs&utm_content=Twitter" target="_blank"><img src="<?php echo esc_attr( plugins_url( 'assets/images/twitter.png', WCCT_FILE ) ); ?>" alt="" class="doc-list-icon"><span><?php esc_html_e( 'Twitter', 'woocommerce-conversion-tracking' ) ?></span></a></li>
                <li><a href="https://wedevs.com/docs/woocommerce-conversion-tracking/google-adwords/?utm_source=wp-admin&utm_medium=docs-link&utm_campaign=wcct_docs&utm_content=Adwords" target="_blank"><img src="<?php echo esc_attr( plugins_url( 'assets/images/adwords.png', WCCT_FILE ) ); ?>" alt="" class="doc-list-icon"><span><?php esc_html_e( 'Google Ads', 'woocommerce-conversion-tracking' ) ?></span></a></li>
                <li><a href="https://wedevs.com/docs/woocommerce-conversion-tracking/bing-ads" target="_blank"><img src="<?php echo esc_attr( plugins_url( 'assets/images/bing_ads.png', WCCT_FILE ) ); ?>" alt="" class="doc-list-icon"><span><?php esc_html_e( 'Microsoft Advertising', 'woocommerce-conversion-tracking' ) ?></span></a></li>
                <li><a href="https://wedevs.com/docs/woocommerce-conversion-tracking/custom/?utm_source=wp-admin&utm_medium=docs-link&utm_campaign=wcct_docs&utm_content=Custom" target="_blank"><img src="<?php echo esc_attr( plugins_url( 'assets/images/custom.png', WCCT_FILE ) ); ?>" alt="" class="doc-list-icon"><span><?php esc_html_e( 'Custom', 'woocommerce-conversion-tracking' ) ?></span></a></li>
            </ul>
        </div>

        <?php do_action( 'wcct_sidebar' ) ?>
    </div>
</div>
