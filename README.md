# Description

Cron scheduled database backup using mysqldump for faster backup operation.

Using PHP to create database backups is a slow task that can easily exceed PHP's max execution time if your site database is a big one, there are solutions to perform the backup in batches using PHP, but mysqldump is way faster compared to any PHP based backup tool.

This plugin calls the mysqldump shell command, which is able to create a database backup file in only a few seconds, even for huge databases.

# Requirements

* PHP 7.0 or higher.
* PHP's exec() function must be enabled. Some hosts may disable it.
* mysqldump shell command must be installed on the server.
* Optional: zip package must be installed on the server if you want to use zip compression.

# Features

* Backup files and the folder to store them have a partially random name, making them unique for each site and hard to guess.
* An index.html file is added to the backups folder to prevent directory listing, also a .htaccess file (for Apache 2.4+ servers) is added blocking access to any web visitor.
* Backup creation is automatically triggered daily by WordPress Cron. This can be customized using a filter.
* Backups are rotated out every week. This can be customized using a filter.

# Usage

- Install and activate it as you do with any other plugin.
- Enjoy! No settings!

# Customization

The following filters are available:

## flashbackup_compression

Possible values are, gz (default), zip, or empty for no compression.

## flashbackup_rotate_time

Time in seconds to delete older backups. By default it's set to delete files older than a week.

## flashbackup_interval

Cron event interval. By default set to daily, so one backup file per day is created. Other WordPress default values for this are: twicedaily, hourly, weekly

You can also use your own custom interval if you have created one.

**For this filter to take effect you need to disable and enable the plugin again to delete the existing cron task and create the new one.**.