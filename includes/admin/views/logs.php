<div class="wrap digitalogic-logs">
    <h1><?php esc_html_e('Activity Logs', 'digitalogic'); ?></h1>
    
    <p><?php esc_html_e('View all activity and changes made in the system', 'digitalogic'); ?></p>
    
    <table id="logs-table" class="display" style="width:100%">
        <thead>
            <tr>
                <th><?php esc_html_e('ID', 'digitalogic'); ?></th>
                <th><?php esc_html_e('User', 'digitalogic'); ?></th>
                <th><?php esc_html_e('Action', 'digitalogic'); ?></th>
                <th><?php esc_html_e('Object Type', 'digitalogic'); ?></th>
                <th><?php esc_html_e('Object ID', 'digitalogic'); ?></th>
                <th><?php esc_html_e('Date', 'digitalogic'); ?></th>
                <th><?php esc_html_e('IP Address', 'digitalogic'); ?></th>
            </tr>
        </thead>
        <tbody>
            <!-- Populated by DataTables -->
        </tbody>
    </table>
</div>
