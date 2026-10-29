<?php
/**
 * Plugin Name:       AdBlock Traffic Tracker
 * Plugin URI:        https://github.com/markovobren/adblock-traffic-tracker
 * Description:       A lightweight WordPress plugin to detect AdBlock users, track unique sessions and pageviews, and send automated daily email reports.
 * Version:           2.1.0
 * Author:            Obren Markov
 * Author URI:        https://github.com/markovobren
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       adblock-traffic-tracker
 * Domain Path:       /languages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==========================================
// 0. HELPER FUNCTIONS (IP AND BOT DETECTION)
// ==========================================

// Reliable IP address detection (supports Cloudflare/Proxy)
function my_get_user_ip() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip_list = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ip_list[0]);
    }
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

// Detection of known bots, crawlers, and automated scripts
function my_is_bot() {
    if (empty($_SERVER['HTTP_USER_AGENT'])) {
        return true; // If there is no User-Agent, it is most likely a bot
    }
    
    $user_agent = strtolower($_SERVER['HTTP_USER_AGENT']);
    $bots = array(
        'bot', 'crawl', 'slurp', 'spider', 'mediapartners', 'lighthouse',
        'facebookexternalhit', 'whatsapp', 'twitterbot', 'telegrambot',
        'bingpreview', 'headlesschrome', 'puppeteer', 'selenium', 'bytespider',
        'yandexbot', 'duckduckbot', 'baiduspider'
    );

    foreach ($bots as $bot) {
        if (strpos($user_agent, $bot) !== false) {
            return true;
        }
    }

    return false;
}

// ==========================================
// 1. EXCLUDING ADMINS/BOTS AND SCRIPT INJECTION
// ==========================================
add_action('wp_head', 'my_adblock_detector_script');
function my_adblock_detector_script() {
    // 1. Exclude bots
    if (my_is_bot()) {
        return;
    }

    // 2. Exclude logged-in admin
    if (current_user_can('manage_options')) {
        return;
    }

    // 3. Exclude if IP address is on the blocked list
    $user_ip = my_get_user_ip();
    $blocked_ips = get_option('adblock_excluded_ips', array());
    if (is_array($blocked_ips) && in_array($user_ip, $blocked_ips)) {
        return;
    }

    ?>
    <script>
    (function() {
        // Check for special test parameter in URL (e.g., mysite.com/?no_stats=1) for testing outside Wi-Fi network
        if (window.location.search.indexOf('no_stats=1') !== -1) {
            sessionStorage.setItem('ignore_adblock_tracking', '1');
        }
        if (sessionStorage.getItem('ignore_adblock_tracking') === '1') {
            return;
        }

        var bait = document.createElement('div');
        bait.className = 'pub_300x250 pub_300x250m media-ad ad-zone ad-space ad_unit';
        bait.style.width = '1px';
        bait.style.height = '1px';
        bait.style.position = 'absolute';
        bait.style.left = '-9999px';
        document.body.appendChild(bait);

        window.setTimeout(function() {
            var isBlocked = false;
            if (bait.offsetParent === null || bait.offsetHeight === 0 || bait.offsetLeft === 0 || bait.clientWidth === 0 || bait.clientHeight === 0) {
                isBlocked = true;
            }
            if (window.getComputedStyle !== undefined) {
                var baitStyle = window.getComputedStyle(bait, null);
                if (baitStyle && (baitStyle.getPropertyValue('display') === 'none' || baitStyle.getPropertyValue('visibility') === 'hidden')) {
                    isBlocked = true;
                }
            }
            if (bait.parentNode) {
                bait.parentNode.removeChild(bait);
            }

            var isNewSession = !sessionStorage.getItem('adblock_session_checked');
            if (isNewSession) {
                sessionStorage.setItem('adblock_session_checked', '1');
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded;');
            xhr.send('action=log_adblock_stats&blocked=' + (isBlocked ? '1' : '0') + '&is_session=' + (isNewSession ? '1' : '0'));

        }, 300);
    })();
    </script>
    <?php
}

// ==========================================
// 2. AJAX REQUEST PROCESSING AND DATABASE LOGGING
// ==========================================
add_action('wp_ajax_log_adblock_stats', 'my_log_adblock_stats');
add_action('wp_ajax_nopriv_log_adblock_stats', 'my_log_adblock_stats');
function my_log_adblock_stats() {
    // Security check for bots
    if (my_is_bot()) {
        wp_send_json_success();
    }

    // Security check for logged-in admins
    if (current_user_can('manage_options')) {
        wp_send_json_success();
    }

    // Check if IP is on the ignored list
    $user_ip = my_get_user_ip();
    $blocked_ips = get_option('adblock_excluded_ips', array());
    if (is_array($blocked_ips) && in_array($user_ip, $blocked_ips)) {
        wp_send_json_success();
    }

    $is_blocked = isset($_POST['blocked']) && $_POST['blocked'] === '1';
    $is_session = isset($_POST['is_session']) && $_POST['is_session'] === '1';

    if (!get_option('adblock_stat_start_date')) {
        update_option('adblock_stat_start_date', current_time('mysql'));
    }

    // 1. Pageviews (every call increases total views)
    $total_pv = (int) get_option('adblock_pv_total', 0);
    $blocked_pv = (int) get_option('adblock_pv_blocked', 0);
    update_option('adblock_pv_total', $total_pv + 1);
    if ($is_blocked) {
        update_option('adblock_pv_blocked', $blocked_pv + 1);
    }

    // 2. Sessions (increases only if it is a new session)
    if ($is_session) {
        $total_sessions = (int) get_option('adblock_session_total', 0);
        $blocked_sessions = (int) get_option('adblock_session_blocked', 0);
        update_option('adblock_session_total', $total_sessions + 1);
        if ($is_blocked) {
            update_option('adblock_session_blocked', $blocked_sessions + 1);
        }
    }

    wp_send_json_success();
}

// ==========================================
// 3. AUTOMATIC EMAIL REPORT SENDING (CRON)
// ==========================================
add_action('wp', 'my_adblock_schedule_cron');
function my_adblock_schedule_cron() {
    if (!wp_next_scheduled('my_adblock_daily_email_event')) {
        wp_schedule_event(time(), 'daily', 'my_adblock_daily_email_event');
    }
}

add_action('my_adblock_daily_email_event', 'my_adblock_send_daily_email');
function my_adblock_send_daily_email() {
    $to = get_option('admin_email');
    $subject = '📊 Daily AdBlock Report - ' . get_bloginfo('name');

    $pv_total = (int) get_option('adblock_pv_total', 0);
    $pv_blocked = (int) get_option('adblock_pv_blocked', 0);
    $pv_pct = $pv_total > 0 ? round(($pv_blocked / $pv_total) * 100, 1) : 0;

    $sess_total = (int) get_option('adblock_session_total', 0);
    $sess_blocked = (int) get_option('adblock_session_blocked', 0);
    $sess_pct = $sess_total > 0 ? round(($sess_blocked / $sess_total) * 100, 1) : 0;

    $start_date = get_option('adblock_stat_start_date');
    $formatted_date = $start_date ? date_i18n('j F Y, H:i', strtotime($start_date)) : 'N/A';

    $message = "Hello,\n\nHere is the daily AdBlock traffic report for the website " . get_bloginfo('name') . ":\n\n";
    $message .= "Measurement period: from " . $formatted_date . "\n";
    $message .= "----------------------------------------\n";
    $message .= "1. UNIQUE SESSIONS:\n";
    $message .= "- Total sessions: " . number_format($sess_total) . "\n";
    $message .= "- With AdBlock: " . number_format($sess_blocked) . " (" . $sess_pct . "%)\n\n";
    $message .= "2. PAGEVIEWS:\n";
    $message .= "- Total views: " . number_format($pv_total) . "\n";
    $message .= "- With AdBlock: " . number_format($pv_blocked) . " (" . $pv_pct . "%)\n";
    $message .= "----------------------------------------\n\n";
    $message .= "This report was automatically generated by your WordPress site.";

    wp_mail($to, $subject, $message);
}

// ==========================================
// 4. DASHBOARD WIDGET DISPLAY
// ==========================================
add_action('wp_dashboard_setup', 'my_add_adblock_dashboard_widget');
function my_add_adblock_dashboard_widget() {
    wp_add_dashboard_widget(
        'my_adblock_stats_widget',
        '📊 AdBlock Traffic Statistics',
        'my_adblock_stats_widget_content'
    );
}

function my_adblock_stats_widget_content() {
    $current_ip = my_get_user_ip();
    $blocked_ips = get_option('adblock_excluded_ips', array());
    if (!is_array($blocked_ips)) {
        $blocked_ips = array();
    }

    // Adding current IP address on button click
    if (isset($_POST['add_current_ip']) && current_user_can('manage_options')) {
        if (!empty($current_ip) && !in_array($current_ip, $blocked_ips)) {
            $blocked_ips[] = $current_ip;
            update_option('adblock_excluded_ips', $blocked_ips);
            echo '<div class="notice notice-success is-dismissible" style="margin: 5px 0 15px 0;"><p>IP address <strong>' . esc_html($current_ip) . '</strong> has been successfully added to the blocked list!</p></div>';
        }
    }

    // Removing individual IP address
    if (isset($_POST['remove_ip']) && current_user_can('manage_options')) {
        $ip_to_remove = sanitize_text_field($_POST['remove_ip']);
        $blocked_ips = array_diff($blocked_ips, array($ip_to_remove));
        update_option('adblock_excluded_ips', array_values($blocked_ips));
        echo '<div class="notice notice-info is-dismissible" style="margin: 5px 0 15px 0;"><p>IP address <strong>' . esc_html($ip_to_remove) . '</strong> has been removed.</p></div>';
    }

    // Reset statistics
    if (isset($_POST['reset_adblock_stats']) && current_user_can('manage_options')) {
        update_option('adblock_pv_total', 0);
        update_option('adblock_pv_blocked', 0);
        update_option('adblock_session_total', 0);
        update_option('adblock_session_blocked', 0);
        update_option('adblock_stat_start_date', current_time('mysql'));
        echo '<div class="notice notice-success is-dismissible" style="margin: 5px 0 15px 0;"><p>Statistics have been successfully reset.</p></div>';
    }

    // Manual trigger for test email sending
    if (isset($_POST['send_test_adblock_email']) && current_user_can('manage_options')) {
        my_adblock_send_daily_email();
        echo '<div class="notice notice-info is-dismissible" style="margin: 5px 0 15px 0;"><p>Test report sent to ' . esc_html(get_option('admin_email')) . '</p></div>';
    }

    $pv_total = (int) get_option('adblock_pv_total', 0);
    $pv_blocked = (int) get_option('adblock_pv_blocked', 0);
    $pv_passed = $pv_total - $pv_blocked;
    $pv_pct = $pv_total > 0 ? round(($pv_blocked / $pv_total) * 100, 1) : 0;

    $sess_total = (int) get_option('adblock_session_total', 0);
    $sess_blocked = (int) get_option('adblock_session_blocked', 0);
    $sess_passed = $sess_total - $sess_blocked;
    $sess_pct = $sess_total > 0 ? round(($sess_blocked / $sess_total) * 100, 1) : 0;

    $start_date = get_option('adblock_stat_start_date');
    $formatted_date = $start_date ? date_i18n('j F Y, H:i', strtotime($start_date)) : 'Not recorded';
    $is_current_ip_blocked = in_array($current_ip, $blocked_ips);
    ?>
    <div style="font-size: 13px; line-height: 1.5;">
        
        <div style="background: #f0f6fc; border-left: 4px solid #72aee6; padding: 8px 12px; margin-bottom: 15px; border-radius: 2px;">
            <strong>Tracking active since:</strong> <?php echo $formatted_date; ?>
        </div>

        <!-- SESSIONS -->
        <h4 style="margin: 0 0 8px 0; font-size: 12px; color: #1d2327; text-transform: uppercase; letter-spacing: 0.5px;">1. Unique Sessions</h4>
        <div style="display: flex; gap: 8px; margin-bottom: 8px; text-align: center;">
            <div style="flex: 1; background: #f6f7f7; padding: 8px; border-radius: 4px; border: 1px solid #dcdcde;">
                <span style="display: block; font-size: 10px; text-transform: uppercase; color: #646970;">Total</span>
                <strong style="font-size: 15px; color: #1d2327;"><?php echo number_format($sess_total); ?></strong>
            </div>
            <div style="flex: 1; background: #fcf0f1; padding: 8px; border-radius: 4px; border: 1px solid #f8cbad;">
                <span style="display: block; font-size: 10px; text-transform: uppercase; color: #d63638;">AdBlock</span>
                <strong style="font-size: 15px; color: #d63638;"><?php echo number_format($sess_blocked); ?></strong>
            </div>
            <div style="flex: 1; background: #f0f8f1; padding: 8px; border-radius: 4px; border: 1px solid #c3e6cb;">
                <span style="display: block; font-size: 10px; text-transform: uppercase; color: #008a20;">No Block</span>
                <strong style="font-size: 15px; color: #008a20;"><?php echo number_format($sess_passed); ?></strong>
            </div>
        </div>
        <p style="margin: 0 0 18px 0; text-align: right; font-size: 12px; color: #50575e;">
            Blocked sessions: <strong style="color: #d63638;"><?php echo $sess_pct; ?>%</strong>
        </p>

        <!-- PAGEVIEWS -->
        <h4 style="margin: 0 0 8px 0; font-size: 12px; color: #1d2327; text-transform: uppercase; letter-spacing: 0.5px;">2. Pageviews</h4>
        <div style="display: flex; gap: 8px; margin-bottom: 8px; text-align: center;">
            <div style="flex: 1; background: #f6f7f7; padding: 8px; border-radius: 4px; border: 1px solid #dcdcde;">
                <span style="display: block; font-size: 10px; text-transform: uppercase; color: #646970;">Total</span>
                <strong style="font-size: 15px; color: #1d2327;"><?php echo number_format($pv_total); ?></strong>
            </div>
            <div style="flex: 1; background: #fcf0f1; padding: 8px; border-radius: 4px; border: 1px solid #f8cbad;">
                <span style="display: block; font-size: 10px; text-transform: uppercase; color: #d63638;">AdBlock</span>
                <strong style="font-size: 15px; color: #d63638;"><?php echo number_format($pv_blocked); ?></strong>
            </div>
            <div style="flex: 1; background: #f0f8f1; padding: 8px; border-radius: 4px; border: 1px solid #c3e6cb;">
                <span style="display: block; font-size: 10px; text-transform: uppercase; color: #008a20;">No Block</span>
                <strong style="font-size: 15px; color: #008a20;"><?php echo number_format($pv_passed); ?></strong>
            </div>
        </div>
        <p style="margin: 0 0 15px 0; text-align: right; font-size: 12px; color: #50575e;">
            Blocked views: <strong style="color: #d63638;"><?php echo $pv_pct; ?>%</strong>
        </p>

        <!-- CURRENT IP ADDRESS LOGGING -->
        <div style="background: #f6f7f7; padding: 12px; border-radius: 4px; border: 1px solid #dcdcde; margin-bottom: 15px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span>Your current IP: <strong><?php echo esc_html($current_ip); ?></strong></span>
                <form method="post" style="margin:0;">
                    <?php if ($is_current_ip_blocked): ?>
                        <span style="color: #008a20; font-weight: 600;">✓ Already excluded</span>
                    <?php else: ?>
                        <button type="submit" name="add_current_ip" value="1" class="button button-small button-primary">Exclude my current IP</button>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (!empty($blocked_ips)): ?>
                <div style="margin-top: 10px; border-top: 1px solid #e2e4e7; padding-top: 8px;">
                    <strong style="display:block; font-size: 11px; color: #646970; margin-bottom: 5px;">Excluded IP addresses:</strong>
                    <ul style="margin: 0; padding: 0; list-style: none;">
                        <?php foreach ($blocked_ips as $ip): ?>
                            <li style="display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 3px 8px; border: 1px solid #e2e4e7; border-radius: 3px; margin-bottom: 4px; font-size: 12px;">
                                <code><?php echo esc_html($ip); ?></code>
                                <form method="post" style="margin:0;">
                                    <input type="hidden" name="remove_ip" value="<?php echo esc_attr($ip); ?>">
                                    <button type="submit" class="button-link" style="color: #d63638; text-decoration: none; font-size: 11px;" onclick="return confirm('Remove this IP address from the list?');">Remove</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>

        <!-- BUTTONS -->
        <form method="post" style="display: flex; justify-content: space-between; border-top: 1px solid #f0f0f1; padding-top: 10px; margin-top: 10px;">
            <button type="submit" name="send_test_adblock_email" value="1" class="button button-small">Send report now</button>
            <button type="submit" name="reset_adblock_stats" value="1" class="button button-secondary button-small" onclick="return confirm('Are you sure you want to reset all data?');">Reset data</button>
        </form>
    </div>
    <?php
}
