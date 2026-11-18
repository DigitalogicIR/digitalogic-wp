<div class="wrap digitalogic-dashboard">
    <h1><?php esc_html_e('Dashboard', 'digitalogic'); ?></h1>
    
    <div class="digitalogic-stats">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=product-list' ) ); ?>" class="stat-box clickable">
            <h3><?php esc_html_e('Total Products', 'digitalogic'); ?></h3>
            <p class="stat-number"><?php echo esc_html(Digitalogic_Number_Formatter::format_integer($product_count)); ?></p>
        </a>
        
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=price-settings' ) ); ?>" class="stat-box clickable">
            <h3><?php esc_html_e('USD Price', 'digitalogic'); ?></h3>
            <p class="stat-number"><?php echo esc_html(Digitalogic_Number_Formatter::format_integer($dollar_price)); ?></p>
        </a>
        
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=price-settings' ) ); ?>" class="stat-box clickable">
            <h3><?php esc_html_e('CNY Price', 'digitalogic'); ?></h3>
            <p class="stat-number"><?php echo esc_html(Digitalogic_Number_Formatter::format_integer($yuan_price)); ?></p>
        </a>
        
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=price-settings' ) ); ?>" class="stat-box clickable">
            <h3><?php esc_html_e('Last Update', 'digitalogic'); ?></h3>
            <p class="stat-number"><?php echo esc_html($update_date); ?></p>
        </a>
    </div>
    
    <div class="digitalogic-quick-links">
        <h2><?php esc_html_e('Quick Links', 'digitalogic'); ?></h2>
        <ul>
            <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=product-list' ) ); ?>" class="button button-primary"><?php esc_html_e('Manage Products', 'digitalogic'); ?></a></li>
            <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=price-settings' ) ); ?>" class="button"><?php esc_html_e('Update Currency Rates', 'digitalogic'); ?></a></li>
            <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=import-export' ) ); ?>" class="button"><?php esc_html_e('Import/Export', 'digitalogic'); ?></a></li>
            <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=digitalogic-logs' ) ); ?>" class="button"><?php esc_html_e('View Logs', 'digitalogic'); ?></a></li>
            <li><a href="<?php echo esc_url(Digitalogic_Laravel_Bridge::instance()->get_launch_url()); ?>" class="button button-primary" target="_blank" rel="noopener"><?php echo esc_html(is_rtl() ? 'باز کردن پنل' : __('Open Panel', 'digitalogic')); ?></a></li>
        </ul>
    </div>
</div>
