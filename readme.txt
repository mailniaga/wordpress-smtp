=== MailNiaga SMTP ===
Contributors: webimpian
Tags: SMTP, email, wp_mail, mailniaga, api, email queue, email log
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.2.8
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.txt

Streamline your WordPress email delivery with Mail Niaga SMTP & API integration. Boost email deliverability, manage email queues, and track email performance.

== Description ==

Mail Niaga SMTP is a powerful WordPress plugin that integrates your website with Mail Niaga's SMTP and API services. This plugin enhances your email deliverability, provides comprehensive tracking capabilities, and ensures a reliable email service for all your outgoing emails.

Mail Niaga is one of the products from Web Impian Sdn Bhd, a leading technology company in Malaysia. By using this plugin, you're leveraging a robust email solution backed by a reputable tech firm.

== How it works ==

This plugin connects to Mail Niaga's endpoint to secure email processing for your WordPress site. It seamlessly integrates with the WordPress core wp_mail() function to ensure all your site's emails are sent through Mail Niaga's reliable SMTP servers or API. With the new email queue system, it can handle large volumes of emails efficiently, making it perfect for email blasts and high-traffic websites.

Please visit our website [https://mailniaga.com/](https://mailniaga.com/) for terms of use and privacy policy, or email to support@mailniaga.com for any inquiries.

== Features ==

* Easy configuration for Mail Niaga SMTP settings
* Full API integration for enhanced performance
* Email Queue system for managing large email sending operations
* Comprehensive Email Log for tracking all outgoing emails
* Email webhook for recording failed email attempts
* Customizable "From" name and email address
* Test email functionality to verify your settings
* Seamless integration with WordPress core `wp_mail()` function
* Improved email deliverability
* Email Credit balance display on top bar dashboard
* Bulk actions for managing email logs (delete all, resend failed)
* Automatic email log cleanup after 7 days

Register as a [**Mail Niaga user here**](https://mailniaga.com/register/)

== Requirements ==

To use Mail Niaga SMTP requires minimum:

* PHP 7.4
* WordPress 5.6

== Installation ==

1. Login to your **WordPress Dashboard**
2. Go to **Plugins > Add New**
3. Search **Mail Niaga SMTP** and click **Install**
4. **Activate** the plugin through the **Plugins** screen in WordPress
5. Go to **Settings > Mail Niaga SMTP** to configure the plugin

= Updating =

While our plugin supports seamless automatic updates, we strongly advise creating a full backup of your site before any update process. This precautionary measure ensures the safety of your data and allows for easy restoration if needed.

== Screenshots ==
1. Mail Niaga SMTP settings page
2. Test email functionality
3. Email Log page
4. Email Queue management
5. Email Credit balance display

== Frequently Asked Questions ==

= Which mailing method should I choose? =

The API method offers improved speed and efficiency, especially for sites that send a high volume of emails.

= What is the Email Queue system? =

The Email Queue system allows you to manage large email sending operations efficiently. It's particularly useful for email blasts or high-traffic websites that need to send a large number of emails without overwhelming the server.

= How long are email logs kept? =

Email logs are automatically removed after 7 days to keep your database clean and efficient.

= What if I don't know my SMTP settings? =

Contact Mail Niaga support or check your Mail Niaga account dashboard for the correct SMTP settings.

= Can I use this plugin with other email providers? =

This plugin is specifically designed for Mail Niaga, a product of Web Impian Sdn Bhd. While it may work with other SMTP providers, we recommend using it with Mail Niaga for the best experience and full feature set.

= Where can I find more information about the Mail Niaga API? =

You can find detailed API documentation at [https://api.webimpian.support/mailniaga](https://api.webimpian.support/mailniaga). This resource provides comprehensive information on how to use the Mail Niaga API effectively.

== Changelog ==

= 2.2.8 =
* Updated dashboard links to the correct Mail Niaga address

= 2.2.7 =
* Fixed the automatic scheduler-record cleanup not removing records on some database versions

= 2.2.6 =
* Automatic cleanup of old scheduler records, running in the background and pausing when the server is busy
* Redesigned settings page showing credit balance, queue and account status at a glance
* Instant API key verification with clear inline status messages
* Test emails send without a page reload
* Email Log and Failed Deliveries pages redesigned for a consistent interface
* New WP-CLI command: wp mailniaga cleanup

= 2.2.5 =
* Delivery reports from Mail Niaga are handled much faster, reducing server load during large sends
* Added a safety limit so a sudden burst of reports cannot slow your site down
* Optional setting for high-volume sites, offered from your dashboard

= 2.2.4 =
* Improved queue scheduling, significantly reducing server and database load
* Emails are no longer lost if your credit runs out or the connection drops. Sending pauses, explains why, and resumes automatically
* Emails that cannot be delivered now stop after 3 attempts, instead of being retried forever
* Faster queue and webhook processing with new database indexes
* Emails interrupted while sending go back to the queue instead of getting stuck
* Reduced database growth from scheduled task logs, with a one-click cleanup for existing sites
* Simpler performance settings, with clearer guidance on sending speed
* A high Concurrency setting is lowered on update. You can change it again in Settings
* Updated bundled libraries, including security updates

= 2.2.3 =
* Migrated admin menu icon to local plugin assets instead of external URL

= 2.2.2 =
* Added automatic recovery for emails stuck in "processing" state
* Added manual recovery functionality in Email Log dashboard
* Enhanced email queue processing reliability and resilience

= 2.2.1 =
* Add support 7.4

= 2.2.0 =
* Added Performance Settings for email processing
* Added Concurrency and Batch Size controls

= 2.1.0 =
* Improve the queue speed email
* Fix a few bug


= 2.0.0 =
* Full API integration with new features:
* Email Queue system for handling larger email sending operations, particularly useful for email blasts
* Comprehensive Email Log for tracking all outgoing emails from the site
* Email webhook implementation for recording failed email attempts
* Improved email sending functionality
* Enhanced filtering on the email log page, including date and search filters
* Custom buttons added for bulk actions:
   - Delete all email logs
   - Resend failed emails
* New bulk actions for deleting emails and resending failed emails
* Email Credit balance display added to the top bar dashboard
* Automatic email log cleanup after 7 days

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 2.2.7 =
Fixes the automatic database cleanup introduced in 2.2.6. Recommended for all sites.

= 2.2.6 =
Refreshed settings page and automatic database cleanup.

= 2.2.5 =
Improves how delivery reports are handled, reducing server load during large sends. Recommended for all sites.

= 2.2.4 =
Important stability and performance update for the email queue. Recommended for all sites.

= 2.0.0 =
Major update with full API integration, email queue system, comprehensive logging, and various UI improvements. Please backup your site before upgrading.

= 1.0.0 =
Initial release of Mail Niaga SMTP plugin.

== Privacy Policy ==

Mail Niaga SMTP plugin does not collect any personal data. However, it facilitates the sending of emails, which may contain personal information. Please ensure that your use of this plugin complies with all relevant privacy laws and regulations.

For more information about Mail Niaga's privacy practices, please visit our [Privacy Policy](https://mailniaga.com/privacy-policy/).

== Terms of Use ==

By using the Mail Niaga SMTP plugin, you agree to comply with our [Terms of Use](https://mailniaga.com/terms-use/). Please review these terms carefully before using the plugin.