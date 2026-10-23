<?php
/**
 * Integra Sentinel — archivio su tabelle dedicate.
 *
 * Tutto cio' che cresce nel tempo (storico eventi, interventi, sonde uptime, metriche giornaliere,
 * registro attivita') vive in tabelle proprie invece che nell'option serializzata dei siti:
 * niente contesa sul lock, storico completo e andamenti consultabili.
 */

if (!defined('ABSPATH')) { exit; }

final class Integra_Sentinel_Store {

    const DB_VERSION = '2';
    const DB_OPTION  = 'integra_sentinel_db_version';
    const RETENTION  = array('events' => 730, 'probes' => 120, 'audit' => 400, 'metrics' => 420);

    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'integra_sentinel_' . $name;
    }

    /** Aggiornamenti del plugin via zip non eseguono l'hook di attivazione: lo schema si verifica a ogni caricamento. */
    public static function maybe_upgrade() {
        if (get_option(self::DB_OPTION) === self::DB_VERSION) { return; }
        if (get_transient('integra_sentinel_db_upgrading')) { return; }
        set_transient('integra_sentinel_db_upgrading', 1, 10 * MINUTE_IN_SECONDS);
        self::install();
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $t = array();
        foreach (array('events', 'tasks', 'audit', 'probes', 'metrics', 'drafts') as $name) { $t[$name] = self::table($name); }
        dbDelta("CREATE TABLE {$t['events']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id varchar(36) NOT NULL DEFAULT '',
  at int(10) unsigned NOT NULL DEFAULT 0,
  type varchar(20) NOT NULL DEFAULT '',
  message text NOT NULL,
  PRIMARY KEY  (id),
  KEY site_at (site_id,at),
  KEY at (at)
) $charset;");
        dbDelta("CREATE TABLE {$t['tasks']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id varchar(36) NOT NULL DEFAULT '',
  title varchar(255) NOT NULL DEFAULT '',
  kind varchar(30) NOT NULL DEFAULT '',
  subject varchar(120) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'open',
  assignee bigint(20) unsigned NOT NULL DEFAULT 0,
  due_date date DEFAULT NULL,
  minutes int(10) unsigned NOT NULL DEFAULT 0,
  notes text NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at int(10) unsigned NOT NULL DEFAULT 0,
  updated_at int(10) unsigned NOT NULL DEFAULT 0,
  closed_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY site_status (site_id,status),
  KEY status (status)
) $charset;");
        dbDelta("CREATE TABLE {$t['audit']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  at int(10) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_login varchar(60) NOT NULL DEFAULT '',
  action varchar(40) NOT NULL DEFAULT '',
  site_id varchar(36) NOT NULL DEFAULT '',
  detail text NOT NULL,
  PRIMARY KEY  (id),
  KEY at (at)
) $charset;");
        dbDelta("CREATE TABLE {$t['probes']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id varchar(36) NOT NULL DEFAULT '',
  at int(10) unsigned NOT NULL DEFAULT 0,
  url_idx tinyint(3) unsigned NOT NULL DEFAULT 0,
  code smallint(5) unsigned NOT NULL DEFAULT 0,
  ms int(10) unsigned NOT NULL DEFAULT 0,
  ok tinyint(1) unsigned NOT NULL DEFAULT 0,
  error varchar(190) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY site_at (site_id,at)
) $charset;");
        dbDelta("CREATE TABLE {$t['metrics']} (
  day date NOT NULL,
  site_id varchar(36) NOT NULL DEFAULT '',
  data text NOT NULL,
  PRIMARY KEY  (day,site_id)
) $charset;");
        dbDelta("CREATE TABLE {$t['drafts']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id varchar(36) NOT NULL DEFAULT '',
  ym char(7) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'draft',
  body text NOT NULL,
  notes text NOT NULL,
  model varchar(120) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at int(10) unsigned NOT NULL DEFAULT 0,
  updated_at int(10) unsigned NOT NULL DEFAULT 0,
  sent_at int(10) unsigned NOT NULL DEFAULT 0,
  sent_to varchar(190) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY site_ym (site_id,ym)
) $charset;");
        if (self::migrate_history()) {
            update_option(self::DB_OPTION, self::DB_VERSION, false);
            delete_transient('integra_sentinel_db_upgrading');
        }
    }

    /** Sposta lo storico 1.7 (max 100 eventi dentro l'option dei siti) nella tabella eventi, mascherando le email. */
    private static function migrate_history() {
        $sites = Integra_Sentinel_Master::sites();
        $has = false;
        foreach ($sites as $site) { if (!empty($site['history'])) { $has = true; break; } }
        if (!$has) { return true; }
        $moved = array();
        foreach ($sites as $id => $site) {
            if (empty($site['history']) || !is_array($site['history'])) { continue; }
            $rows = array();
            foreach ($site['history'] as $event) {
                if (!is_array($event) || empty($event['event'])) { continue; }
                $message = Integra_Sentinel_Master::mask_emails((string) $event['event']);
                $rows[] = array('at' => (int) ($event['at'] ?? 0), 'type' => self::event_type($message), 'message' => $message);
            }
            self::add_events((string) $id, $rows);
            $moved[] = (string) $id;
        }
        return Integra_Sentinel_Master::mutate_sites(function($current) use ($moved) {
            foreach ($moved as $id) { if (isset($current[$id])) { unset($current[$id]['history']); } }
            return $current;
        });
    }

    public static function event_type($message) {
        $message = (string) $message;
        if (strpos($message, 'SECURITY') === 0 || stripos($message, 'amministratore') !== false) { return 'admin'; }
        if (strpos($message, 'Installato') === 0) { return 'install'; }
        if (strpos($message, 'Rimosso') === 0) { return 'remove'; }
        if (strpos($message, 'WordPress:') === 0) { return 'core'; }
        if (strpos($message, '→') !== false) { return 'update'; }
        return 'note';
    }

    /* ---------- Eventi (storico versioni e sicurezza) ---------- */

    public static function add_events($site_id, $rows) {
        global $wpdb;
        $table = self::table('events');
        foreach (array_slice((array) $rows, 0, 300) as $row) {
            $wpdb->insert($table, array(
                'site_id' => substr((string) $site_id, 0, 36),
                'at' => (int) ($row['at'] ?? time()),
                'type' => substr((string) ($row['type'] ?? 'note'), 0, 20),
                'message' => function_exists('mb_substr') ? mb_substr((string) ($row['message'] ?? ''), 0, 500) : substr((string) ($row['message'] ?? ''), 0, 500),
            ), array('%s', '%d', '%s', '%s'));
        }
    }

    public static function events($site_id, $since = 0, $until = 0, $limit = 200, $type = '') {
        global $wpdb;
        $table = self::table('events');
        $where = $wpdb->prepare('site_id = %s AND at >= %d', (string) $site_id, (int) $since);
        if ($until) { $where .= $wpdb->prepare(' AND at < %d', (int) $until); }
        if ($type !== '') { $where .= $wpdb->prepare(' AND type = %s', $type); }
        $rows = $wpdb->get_results("SELECT at, type, message FROM {$table} WHERE {$where} ORDER BY at DESC, id DESC LIMIT " . max(1, min(2000, (int) $limit)), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /** Eventi recenti di tutto il parco (es. plugin installati di recente). */
    public static function recent_events($since, $type = '', $limit = 500) {
        global $wpdb;
        $table = self::table('events');
        $where = $wpdb->prepare('at >= %d', (int) $since);
        if ($type !== '') { $where .= $wpdb->prepare(' AND type = %s', $type); }
        $rows = $wpdb->get_results("SELECT site_id, at, type, message FROM {$table} WHERE {$where} ORDER BY at DESC LIMIT " . max(1, min(5000, (int) $limit)), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /* ---------- Registro attivita' del Master ---------- */

    public static function log($action, $detail = '', $site_id = '') {
        global $wpdb;
        $user = wp_get_current_user();
        $login = (string) ($user->user_login ?? '');
        if ($login === '') { $login = defined('WP_CLI') && WP_CLI ? 'wp-cli' : 'sistema'; }
        $wpdb->insert(self::table('audit'), array(
            'at' => time(),
            'user_id' => (int) ($user->ID ?? 0),
            'user_login' => substr($login, 0, 60),
            'action' => substr(sanitize_key($action), 0, 40),
            'site_id' => substr((string) $site_id, 0, 36),
            'detail' => function_exists('mb_substr') ? mb_substr((string) $detail, 0, 500) : substr((string) $detail, 0, 500),
        ), array('%d', '%d', '%s', '%s', '%s', '%s'));
    }

    public static function audit_entries($limit = 200) {
        global $wpdb;
        $table = self::table('audit');
        $rows = $wpdb->get_results("SELECT at, user_login, action, site_id, detail FROM {$table} ORDER BY id DESC LIMIT " . max(1, min(1000, (int) $limit)), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /* ---------- Interventi ---------- */

    public static function task_statuses() {
        return array('open' => 'Da fare', 'progress' => 'In corso', 'client' => 'In attesa del cliente', 'hosting' => 'In attesa dell’hosting', 'vendor' => 'In attesa del fornitore', 'done' => 'Risolto');
    }

    public static function task_save($data) {
        global $wpdb;
        $table = self::table('tasks');
        $statuses = self::task_statuses();
        $row = array(
            'site_id' => substr((string) ($data['site_id'] ?? ''), 0, 36),
            'title' => substr(sanitize_text_field((string) ($data['title'] ?? '')), 0, 255),
            'kind' => substr(sanitize_key((string) ($data['kind'] ?? '')), 0, 30),
            'subject' => substr(sanitize_text_field((string) ($data['subject'] ?? '')), 0, 120),
            'status' => isset($statuses[$data['status'] ?? '']) ? $data['status'] : 'open',
            'assignee' => absint($data['assignee'] ?? 0),
            'due_date' => (!empty($data['due_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['due_date'])) ? $data['due_date'] : null,
            'minutes' => min(100000, absint($data['minutes'] ?? 0)),
            'notes' => substr(sanitize_textarea_field((string) ($data['notes'] ?? '')), 0, 4000),
            'updated_at' => time(),
        );
        if ($row['title'] === '') { return 0; }
        $id = absint($data['id'] ?? 0);
        if ($id) {
            $old = self::task_get($id);
            if (!$old) { return 0; }
            $row['closed_at'] = $row['status'] === 'done' ? ((int) $old['closed_at'] ?: time()) : 0;
            $wpdb->update($table, $row, array('id' => $id), self::task_formats($row), array('%d'));
            return $id;
        }
        $row['created_by'] = get_current_user_id();
        $row['created_at'] = time();
        $row['closed_at'] = $row['status'] === 'done' ? time() : 0;
        return $wpdb->insert($table, $row, self::task_formats($row)) ? (int) $wpdb->insert_id : 0;
    }

    /** Formati espliciti: senza, wpdb tratta "site_id" come intero (colonna del multisito) e tronca l'UUID. */
    private static function task_formats($row) {
        $ints = array('assignee', 'minutes', 'created_by', 'created_at', 'updated_at', 'closed_at');
        $formats = array();
        foreach (array_keys($row) as $key) { $formats[] = in_array($key, $ints, true) ? '%d' : '%s'; }
        return $formats;
    }

    public static function task_get($id) {
        global $wpdb;
        $table = self::table('tasks');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", absint($id)), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function task_set_status($id, $status, $note = '') {
        global $wpdb;
        if (!isset(self::task_statuses()[$status])) { return false; }
        $task = self::task_get($id);
        if (!$task) { return false; }
        $fields = array('status' => $status, 'updated_at' => time(), 'closed_at' => $status === 'done' ? ((int) $task['closed_at'] ?: time()) : 0);
        if ($note !== '') { $fields['notes'] = substr(trim($task['notes'] . "\n" . wp_date('d/m/Y H:i') . ' · ' . $note), 0, 4000); }
        return false !== $wpdb->update(self::table('tasks'), $fields, array('id' => absint($id)), self::task_formats($fields), array('%d'));
    }

    public static function task_delete($id) {
        global $wpdb;
        return (bool) $wpdb->delete(self::table('tasks'), array('id' => absint($id)), array('%d'));
    }

    /** @param array $args site_id, status ('open' = tutti tranne risolti), assignee, limit */
    public static function tasks($args = array()) {
        global $wpdb;
        $table = self::table('tasks');
        $where = array('1=1');
        if (!empty($args['site_id'])) { $where[] = $wpdb->prepare('site_id = %s', (string) $args['site_id']); }
        if (!empty($args['status'])) {
            if ($args['status'] === 'active') { $where[] = "status <> 'done'"; }
            else { $where[] = $wpdb->prepare('status = %s', (string) $args['status']); }
        }
        if (!empty($args['assignee'])) { $where[] = $wpdb->prepare('assignee = %d', absint($args['assignee'])); }
        $limit = max(1, min(1000, (int) ($args['limit'] ?? 300)));
        $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY (status = 'done') ASC, (due_date IS NULL) ASC, due_date ASC, id DESC LIMIT {$limit}", ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public static function task_counts() {
        global $wpdb;
        $table = self::table('tasks');
        $rows = $wpdb->get_results("SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A);
        $out = array();
        foreach ((array) $rows as $r) { $out[$r['status']] = (int) $r['n']; }
        return $out;
    }

    /* ---------- Resoconti per i clienti (bozze scritte con l'IA o a mano, una per sito e mese) ---------- */

    public static function draft_statuses() {
        return array('draft' => 'Bozza da rileggere', 'approved' => 'Approvato', 'sent' => 'Inviato');
    }

    public static function draft_get($site_id, $ym) {
        global $wpdb;
        $table = self::table('drafts');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE site_id = %s AND ym = %s", (string) $site_id, (string) $ym), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** Bozze di un mese per tutti i siti: site_id => riga. */
    public static function drafts_for_month($ym) {
        global $wpdb;
        $table = self::table('drafts');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE ym = %s", (string) $ym), ARRAY_A);
        $out = array();
        foreach ((array) $rows as $row) { $out[$row['site_id']] = $row; }
        return $out;
    }

    /** Crea o aggiorna la bozza del mese; i campi assenti restano invariati. */
    public static function draft_save($site_id, $ym, $fields) {
        global $wpdb;
        if (!preg_match('/^\d{4}-\d{2}$/', (string) $ym) || $site_id === '') { return false; }
        $row = array('updated_at' => time());
        if (array_key_exists('body', $fields)) { $row['body'] = substr(sanitize_textarea_field((string) $fields['body']), 0, 20000); }
        if (array_key_exists('notes', $fields)) { $row['notes'] = substr(sanitize_textarea_field((string) $fields['notes']), 0, 4000); }
        if (isset($fields['status']) && isset(self::draft_statuses()[$fields['status']])) { $row['status'] = $fields['status']; }
        if (array_key_exists('model', $fields)) { $row['model'] = substr(sanitize_text_field((string) $fields['model']), 0, 120); }
        if (!empty($fields['sent_to'])) { $row['sent_to'] = substr(sanitize_email((string) $fields['sent_to']), 0, 190); $row['sent_at'] = time(); }
        $formats = function($r) { $f = array(); foreach (array_keys($r) as $k) { $f[] = in_array($k, array('created_by', 'created_at', 'updated_at', 'sent_at'), true) ? '%d' : '%s'; } return $f; };
        $old = self::draft_get($site_id, $ym);
        if ($old) { return false !== $wpdb->update(self::table('drafts'), $row, array('id' => (int) $old['id']), $formats($row), array('%d')); }
        $row += array('site_id' => substr((string) $site_id, 0, 36), 'ym' => $ym, 'status' => 'draft', 'body' => '', 'notes' => '', 'model' => '',
            'created_by' => get_current_user_id(), 'created_at' => time());
        return (bool) $wpdb->insert(self::table('drafts'), $row, $formats($row));
    }

    /* ---------- Sonde uptime ---------- */

    public static function add_probes($rows) {
        global $wpdb;
        $table = self::table('probes');
        foreach ((array) $rows as $row) {
            $wpdb->insert($table, array(
                'site_id' => substr((string) $row['site_id'], 0, 36), 'at' => (int) $row['at'], 'url_idx' => min(9, (int) $row['url_idx']),
                'code' => min(999, (int) $row['code']), 'ms' => min(600000, (int) $row['ms']), 'ok' => $row['ok'] ? 1 : 0,
                'error' => substr((string) $row['error'], 0, 190),
            ), array('%s', '%d', '%d', '%d', '%d', '%d', '%s'));
        }
    }

    public static function probes($site_id, $since, $limit = 500) {
        global $wpdb;
        $table = self::table('probes');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT at, url_idx, code, ms, ok, error FROM {$table} WHERE site_id = %s AND at >= %d ORDER BY at DESC LIMIT %d", (string) $site_id, (int) $since, max(1, min(5000, (int) $limit))), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /** Percentuale di controlli riusciti sulla home (url_idx 0) nel periodo; null se non ci sono dati. */
    public static function uptime($site_id, $since, $until = 0) {
        global $wpdb;
        $table = self::table('probes');
        $sql = $wpdb->prepare("SELECT COUNT(*) AS n, SUM(ok) AS ok, AVG(ms) AS ms FROM {$table} WHERE site_id = %s AND url_idx = 0 AND at >= %d", (string) $site_id, (int) $since);
        if ($until) { $sql .= $wpdb->prepare(' AND at < %d', (int) $until); }
        $row = $wpdb->get_row($sql, ARRAY_A);
        if (!$row || empty($row['n'])) { return null; }
        return array('pct' => round(100 * (int) $row['ok'] / (int) $row['n'], 2), 'checks' => (int) $row['n'], 'ms' => (int) round((float) $row['ms']));
    }

    /* ---------- Metriche giornaliere ---------- */

    public static function save_metrics($day, $site_id, $data) {
        global $wpdb;
        $wpdb->replace(self::table('metrics'), array('day' => $day, 'site_id' => substr((string) $site_id, 0, 36), 'data' => wp_json_encode($data)), array('%s', '%s', '%s'));
    }

    /** @return array day => site_id => data */
    public static function metrics_since($since_day, $site_id = '') {
        global $wpdb;
        $table = self::table('metrics');
        $sql = $wpdb->prepare("SELECT day, site_id, data FROM {$table} WHERE day >= %s", $since_day);
        if ($site_id !== '') { $sql .= $wpdb->prepare(' AND site_id = %s', $site_id); }
        $rows = $wpdb->get_results($sql . ' ORDER BY day ASC', ARRAY_A);
        $out = array();
        foreach ((array) $rows as $r) {
            $data = json_decode((string) $r['data'], true);
            if (is_array($data)) { $out[$r['day']][$r['site_id']] = $data; }
        }
        return $out;
    }

    /* ---------- Pulizia periodica ---------- */

    public static function cleanup() {
        global $wpdb;
        $now = time();
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('events') . ' WHERE at < %d', $now - self::RETENTION['events'] * DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('probes') . ' WHERE at < %d', $now - self::RETENTION['probes'] * DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('audit') . ' WHERE at < %d', $now - self::RETENTION['audit'] * DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('metrics') . ' WHERE day < %s', gmdate('Y-m-d', $now - self::RETENTION['metrics'] * DAY_IN_SECONDS)));
    }

    /** Rimozione di un sito: elimina i dati collegati nelle tabelle. */
    public static function forget_site($site_id) {
        global $wpdb;
        foreach (array('events', 'probes', 'metrics', 'drafts') as $name) {
            $wpdb->delete(self::table($name), array('site_id' => (string) $site_id), array('%s'));
        }
    }
}
