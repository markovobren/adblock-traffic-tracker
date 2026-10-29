# AdBlock Traffic Tracker

A lightweight, high-performance WordPress plugin designed to detect AdBlock users, track unique sessions and pageviews, and send automated daily email reports to the site administrator - all without relying on external third-party services.

## Features

- **Reliable IP Detection:** Supports Cloudflare and standard proxy headers.
- **Smart Bot & Admin Filtering:** Automatically excludes known crawlers, bots, and logged-in administrators from skewing statistics.
- **AJAX-Based Tracking:** Asynchronously tracks pageviews and unique sessions via lightweight JavaScript.
- **Admin Dashboard Widget:** View real-time statistics, percentage breakdowns, and manage an excluded IP list directly from your WordPress dashboard.
- **Automated Daily Reports:** Built-in WP-Cron system sends a summary report straight to the site admin email.

## Installation

1. Download the ZIP file of this repository or clone it into your `/wp-content/plugins/` directory.
2. Ensure the main plugin file is placed in a folder named `adblock-traffic-tracker`.
3. Activate the plugin through the **Plugins** menu in your WordPress administration panel.
4. Check your WordPress Dashboard to view real-time AdBlock statistics.

## License

This project is licensed under the GPL v2 or later - see the [LICENSE](LICENSE) file for details.
