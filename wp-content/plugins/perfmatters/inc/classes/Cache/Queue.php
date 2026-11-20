<?php
namespace Perfmatters\Cache;

if(!defined('ABSPATH')) {
	exit;
}

class Queue
{
	const VERSION = '1';
	const VERSION_OPTION = 'perfmatters_cache_warmer_db';

	//set after a successful install check so claim/enqueue/counts skip SHOW TABLES this request
	private static $ready = false;

	//one row per url, so a batch updates that row instead of rewriting the whole list
	public static function install()
	{
		if(self::$ready) {
			return;
		}

		if(get_option(self::VERSION_OPTION, '') === self::VERSION && self::exists()) {
			self::$ready = true;
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url varchar(2048) NOT NULL,
			url_hash char(32) NOT NULL,
			status varchar(12) NOT NULL DEFAULT 'pending',
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			priority tinyint(3) unsigned NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY status_priority (status, priority, id)
		) {$charset};";

		dbDelta($sql);

		if(get_option(self::VERSION_OPTION, null) === null) {
			add_option(self::VERSION_OPTION, self::VERSION, '', false);
		}
		else {
			update_option(self::VERSION_OPTION, self::VERSION, false);
		}

		self::$ready = true;
	}

	//drop the queue when the helper is deactivated
	public static function drop()
	{
		global $wpdb;

		$wpdb->query('DROP TABLE IF EXISTS ' . self::table());
		delete_option(self::VERSION_OPTION);
		self::$ready = false;
	}

	//cache was turned off, so the progress rows should not linger
	public static function clear_rows()
	{
		self::install();

		global $wpdb;

		$wpdb->query('DELETE FROM ' . self::table());
	}

	//files were wiped, so every row needs a fetch before it counts as cached
	public static function mark_pending()
	{
		self::install();

		global $wpdb;

		$wpdb->query('UPDATE ' . self::table() . " SET status = 'pending', attempts = 0, priority = 1");
	}

	//replace the queue with a fresh list, home first, all waiting to be fetched
	public static function replace_all($urls)
	{
		self::install();

		global $wpdb;

		$wpdb->query('DELETE FROM ' . self::table());
		self::write(self::clean($urls), 'pending', 1, false);
	}

	//put these urls at the front. an existing row is fetched again
	public static function enqueue($urls, $priority = 0)
	{
		$urls = self::clean($urls);

		if(empty($urls)) {
			return;
		}

		self::install();
		self::write($urls, 'pending', (int) $priority, true);
	}

	//a renamed or deleted post should leave the list
	public static function forget($urls)
	{
		$hashes = array();

		foreach(self::clean($urls) as $url) {
			$hashes[] = md5($url);
		}

		self::delete_hashes($hashes);
	}

	//done, pending, in_progress, and failed for the warmer status card
	public static function counts()
	{
		self::install();

		global $wpdb;

		$counts = array(
			'total' => 0,
			'done' => 0,
			'pending' => 0,
			'in_progress' => 0,
			'failed' => 0
		);

		$rows = $wpdb->get_results('SELECT status, COUNT(*) AS total FROM ' . self::table() . ' GROUP BY status', ARRAY_A);

		if(!is_array($rows)) {
			return $counts;
		}

		foreach($rows as $row) {
			$total = (int) $row['total'];
			$counts['total'] += $total;

			if($row['status'] === 'done') {
				$counts['done'] = $total;
			}
			elseif($row['status'] === 'failed') {
				$counts['failed'] = $total;
			}
			elseif($row['status'] === 'in-progress') {
				//DB status uses a hyphen; array key stays in_progress for callers
				$counts['in_progress'] = $total;
			}
			else {
				$counts['pending'] += $total;
			}
		}

		return $counts;
	}

	//true when a batch still has work, including a row left in progress by a dead request
	public static function unfinished()
	{
		self::install();

		global $wpdb;

		return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table() . " WHERE status IN ('pending', 'in-progress')") > 0;
	}

	//claim the next url. partial purges use a lower priority so they run first
	public static function claim()
	{
		self::install();

		global $wpdb;

		$table = self::table();

		for($attempt = 0; $attempt < 5; $attempt++) {
			$row = $wpdb->get_row("SELECT id, url, attempts FROM {$table} WHERE status = 'pending' ORDER BY priority ASC, id ASC LIMIT 1");

			if(!$row) {
				return null;
			}

			$claimed = $wpdb->query($wpdb->prepare("UPDATE {$table} SET status = 'in-progress' WHERE id = %d AND status = 'pending'", $row->id));

			if($claimed) {
				return $row;
			}
		}

		return null;
	}

	public static function complete($id)
	{
		self::set_status($id, 'done');
	}

	public static function fail($id)
	{
		self::set_status($id, 'failed');
	}

	//one more pass after the urls that have not been tried yet
	public static function retry($id, $attempts)
	{
		global $wpdb;

		$wpdb->update(self::table(), array(
			'status' => 'pending',
			'attempts' => (int) $attempts,
			'priority' => 2
		), array(
			'id' => (int) $id
		), array('%s', '%d', '%d'), array('%d'));
	}

	//a dead batch should not leave its url stuck
	public static function release($id)
	{
		global $wpdb;

		$wpdb->query($wpdb->prepare("UPDATE " . self::table() . " SET status = 'pending' WHERE id = %d AND status = 'in-progress'", (int) $id));
	}

	public static function release_in_progress()
	{
		self::install();

		global $wpdb;

		$wpdb->query("UPDATE " . self::table() . " SET status = 'pending' WHERE status = 'in-progress'");
	}

	public static function table()
	{
		global $wpdb;

		return $wpdb->prefix . 'perfmatters_cache_warmer';
	}

	private static function exists()
	{
		global $wpdb;

		$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(self::table())));

		return $found === self::table();
	}

	private static function write($urls, $status, $priority, $replace)
	{
		if(empty($urls)) {
			return;
		}

		global $wpdb;

		$table = self::table();
		$chunks = array_chunk($urls, 100);

		foreach($chunks as $chunk) {
			$values = array();
			$args = array();

			foreach($chunk as $url) {
				$values[] = '(%s, %s, %s, 0, %d)';
				$args[] = $url;
				$args[] = md5($url);
				$args[] = $status;
				$args[] = $priority;
			}

			$sql = "INSERT INTO {$table} (url, url_hash, status, attempts, priority) VALUES " . implode(', ', $values);

			if($replace) {
				$sql .= " ON DUPLICATE KEY UPDATE status = 'pending', attempts = 0, priority = " . (int) $priority;
			}

			$wpdb->query($wpdb->prepare($sql, $args));
		}
	}

	private static function delete_hashes($hashes)
	{
		$hashes = array_values(array_filter($hashes));

		if(empty($hashes)) {
			return;
		}

		self::install();

		global $wpdb;

		$table = self::table();

		foreach(array_chunk($hashes, 100) as $chunk) {
			$placeholders = implode(', ', array_fill(0, count($chunk), '%s'));
			$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE url_hash IN ({$placeholders})", $chunk));
		}
	}

	private static function set_status($id, $status)
	{
		global $wpdb;

		$wpdb->update(self::table(), array(
			'status' => $status
		), array(
			'id' => (int) $id
		), array('%s'), array('%d'));
	}

	private static function clean($urls)
	{
		$clean = array();

		foreach((array) $urls as $url) {
			if(!is_string($url) || $url === '' || strlen($url) > 2048) {
				continue;
			}

			$clean[] = $url;
		}

		return array_values(array_unique($clean));
	}
}
