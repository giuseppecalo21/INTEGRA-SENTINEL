<?php
/**
 * Integra Sentinel — valutazione dell'ambiente del sito: cio' che impedisce agli aggiornamenti di funzionare.
 *
 * Classe pura: riceve l'inventario gia' sanificato (blocco "env" del Client 1.7) e restituisce
 * schede leggibili e "colli di bottiglia" nello stesso formato del motore delle dipendenze.
 */

if (!defined('ABSPATH')) { exit; }

final class Integra_Sentinel_Environment {

    /** Slug di plugin che eseguono backup (o servizi di gestione che li includono). */
    public static function backup_slugs() {
        return array('updraftplus', 'backwpup', 'duplicator', 'duplicator-pro', 'wpvivid-backuprestore', 'wpvivid-backup-pro', 'all-in-one-wp-migration',
            'backupbuddy', 'solid-backups', 'blogvault-real-time-backup', 'jetpack-backup', 'xcloner-backup-and-restore', 'wp-time-capsule',
            'boldgrid-backup', 'backup-backup', 'worker', 'mainwp-child', 'iwp-client', 'snapshot-backups', 'wp-db-backup');
    }

    /** "256M", "1G", "-1" → byte (-1 = illimitata, 0 = sconosciuta). */
    public static function bytes($value) {
        $value = trim((string) $value);
        if ($value === '') { return 0; }
        if ($value === '-1') { return -1; }
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([kmg]?)/i', $value, $m)) { return 0; }
        $n = (float) $m[1];
        $unit = strtolower($m[2]);
        return (int) ($unit === 'g' ? $n * 1073741824 : ($unit === 'm' ? $n * 1048576 : ($unit === 'k' ? $n * 1024 : $n)));
    }

    private static function issue($kind, $subject, $name, $severity, $owner, $title, $detail, $action, $score) {
        return array('id' => $kind . ':' . $subject, 'kind' => $kind, 'subject' => $subject, 'subjectName' => $name, 'severity' => $severity,
            'owner' => $owner, 'title' => $title, 'detail' => $detail, 'action' => $action, 'unlocks' => array(), 'security' => false,
            'score' => $score, 'node' => '');
    }

    private static function check($key, $label, $value, $tone, $hint = '') {
        return array('key' => $key, 'label' => $label, 'value' => $value, 'tone' => $tone, 'hint' => $hint);
    }

    /**
     * @param array $report inventario sanificato
     * @param array $ctx    policies, meta (scheda cliente), plugins (slug => plugin), has_envato (bool)
     */
    public static function evaluate($report, $ctx = array()) {
        $report = is_array($report) ? $report : array();
        $env = is_array($report['env'] ?? null) ? $report['env'] : array();
        $policies = (array) ($ctx['policies'] ?? array());
        $meta = (array) ($ctx['meta'] ?? array());
        $plugins = (array) ($ctx['plugins'] ?? array());
        $checks = array(); $issues = array();

        // PHP: data di fine supporto invece di una soglia fissa.
        $php = (string) ($report['php'] ?? '');
        if ($php !== '') {
            $e = Integra_Sentinel_EOL::evaluate('php', $php);
            $tone = $e['state'] === 'eol' ? 'danger' : ($e['state'] === 'soon' ? 'warn' : ($e['state'] === 'ok' ? 'ok' : 'muted'));
            $checks[] = self::check('php', 'PHP', 'PHP ' . $php, $tone, Integra_Sentinel_EOL::sentence('php', $php));
            if ($e['state'] === 'eol' || $e['state'] === 'soon') {
                $issues[] = self::issue('platform', 'php-' . $e['branch'], 'PHP ' . $e['branch'], $e['state'] === 'eol' ? 'high' : 'medium', 'hosting',
                    $e['state'] === 'eol' ? 'PHP ' . $e['branch'] . ' fuori supporto di sicurezza' : 'PHP ' . $e['branch'] . ' esce dal supporto tra ' . $e['days'] . ' giorni',
                    Integra_Sentinel_EOL::sentence('php', $php) . ' Senza patch del linguaggio, molti plugin smettono di rilasciare versioni compatibili.',
                    'Pianificare con l’hosting il passaggio a una versione supportata, provandola prima in staging.', $e['state'] === 'eol' ? 30 : 12);
            }
        }

        if (!$env) {
            $checks[] = self::check('client', 'Ambiente', 'Dati non disponibili', 'muted', 'Aggiorna il Client alla 1.7 per i controlli su permessi, spazio, memoria, WP-Cron e backup.');
            return array('checks' => $checks, 'issues' => $issues, 'available' => false);
        }

        // Database.
        $db = (array) ($env['db'] ?? array());
        $db_type = ($db['type'] ?? '') === 'mariadb' ? 'mariadb' : 'mysql';
        $db_version = (string) ($db['version'] ?? '');
        if ($db_version !== '') {
            $e = Integra_Sentinel_EOL::evaluate($db_type, $db_version);
            $tone = $e['state'] === 'eol' ? 'danger' : ($e['state'] === 'soon' ? 'warn' : ($e['state'] === 'ok' ? 'ok' : 'muted'));
            $checks[] = self::check('db', 'Database', Integra_Sentinel_EOL::label($db_type) . ' ' . $db_version, $tone, Integra_Sentinel_EOL::sentence($db_type, $db_version));
            if ($e['state'] === 'eol') {
                $issues[] = self::issue('platform', $db_type . '-' . $e['branch'], Integra_Sentinel_EOL::label($db_type) . ' ' . $e['branch'], 'medium', 'hosting',
                    Integra_Sentinel_EOL::label($db_type) . ' ' . $e['branch'] . ' fuori supporto', Integra_Sentinel_EOL::sentence($db_type, $db_version),
                    'Chiedere all’hosting la migrazione a una versione supportata del database.', 10);
            }
        }

        // Il pannello puo' installare e aggiornare?
        $writable = (array) ($env['writable'] ?? array());
        $fs = (string) ($env['fs_method'] ?? '');
        $blocks = array();
        if (empty($env['file_mods'])) {
            $blocks[] = 'DISALLOW_FILE_MODS';
            $issues[] = self::issue('server', 'file_mods', 'DISALLOW_FILE_MODS', 'high', 'agenzia', 'Aggiornamenti disattivati da wp-config.php',
                'La costante DISALLOW_FILE_MODS impedisce a WordPress di installare o aggiornare core, plugin e temi.',
                'Toglierla per la finestra di manutenzione oppure aggiornare via SFTP o WP-CLI.', 35);
        }
        if ($fs !== '' && $fs !== 'direct' && empty($env['fs_credentials'])) {
            $blocks[] = 'FTP richiesto';
            $issues[] = self::issue('server', 'fs_method', 'Credenziali FTP richieste', 'high', 'hosting', 'WordPress chiede le credenziali FTP per aggiornare',
                'Il server non permette la scrittura diretta dei file (metodo ' . $fs . '): gli aggiornamenti automatici non partono.',
                'Chiedere all’hosting di allineare il proprietario dei file all’utente PHP.', 30);
        }
        foreach (array('plugins' => 'dei plugin', 'themes' => 'dei temi') as $key => $label) {
            if (array_key_exists($key, $writable) && !$writable[$key]) {
                $blocks[] = 'cartella ' . $label;
                $issues[] = self::issue('server', 'writable_' . $key, 'Cartella ' . $label . ' non scrivibile', 'high', 'hosting', 'Cartella ' . $label . ' non scrivibile',
                    'WordPress non può sostituire i file durante l’aggiornamento.', 'Correggere permessi e proprietario della cartella con l’hosting.', 30);
            }
        }
        $checks[] = self::check('updates', 'Aggiornamenti dal pannello', $blocks ? 'Bloccati' : 'Possibili', $blocks ? 'danger' : 'ok',
            $blocks ? 'Impedimenti: ' . implode(', ', $blocks) . '.' : 'Permessi e metodo di scrittura adatti agli aggiornamenti.');

        // Spazio su disco.
        $disk = $env['disk_free_mb'] ?? null;
        $min = max(100, (int) ($policies['disk_min_mb'] ?? 500));
        if ($disk === null || $disk === '') {
            $checks[] = self::check('disk', 'Spazio libero', 'Non rilevabile', 'muted', 'L’hosting non permette di leggere lo spazio libero.');
        } else {
            $disk = (int) $disk;
            $tone = $disk < 100 ? 'danger' : ($disk < $min ? 'warn' : 'ok');
            $checks[] = self::check('disk', 'Spazio libero', self::mb($disk), $tone, 'Soglia di attenzione: ' . self::mb($min) . '.');
            if ($disk < $min) {
                $issues[] = self::issue('server', 'disk', 'Spazio su disco', $disk < 100 ? 'critical' : 'high', 'hosting', 'Spazio su disco quasi esaurito (' . self::mb($disk) . ')',
                    'Ogni aggiornamento scarica, estrae e conserva un backup temporaneo del pacchetto: con poco spazio fallisce a metà.',
                    'Liberare spazio (backup locali, log, cache) o aumentare la quota con l’hosting.', $disk < 100 ? 45 : 25);
            }
        }

        // Memoria.
        $mem = self::bytes($env['memory_limit'] ?? '');
        $wp_mem = self::bytes($env['wp_memory_limit'] ?? '');
        $effective = $mem === -1 ? -1 : max($mem, 0);
        if ($effective === 0 && $wp_mem > 0) { $effective = $wp_mem; }
        if ($effective === 0) {
            $checks[] = self::check('memory', 'Memoria PHP', 'Non rilevata', 'muted');
        } else {
            $label = $effective === -1 ? 'Illimitata' : self::mb((int) round($effective / 1048576));
            $tone = $effective === -1 || $effective >= 268435456 ? 'ok' : ($effective >= 134217728 ? 'warn' : 'danger');
            $checks[] = self::check('memory', 'Memoria PHP', $label, $tone, 'Consigliata almeno 256 MB con WooCommerce o page builder.');
            if ($effective !== -1 && $effective < 134217728) {
                $issues[] = self::issue('server', 'memory', 'Memoria PHP', 'medium', 'hosting', 'Memoria PHP insufficiente (' . $label . ')',
                    'Gli aggiornamenti dei plugin grandi (WooCommerce, Elementor) possono interrompersi per esaurimento della memoria.',
                    'Portare memory_limit ad almeno 256 MB.', 12);
            }
        }

        // WP-Cron.
        $cron = (array) ($env['cron'] ?? array());
        $oldest = (int) ($cron['oldest'] ?? 0);
        $late = !empty($cron['overdue']) && $oldest && (time() - $oldest) > 6 * HOUR_IN_SECONDS;
        $checks[] = self::check('cron', 'WP-Cron', !empty($cron['disabled']) ? 'Cron di sistema' : 'WP-Cron', $late ? 'warn' : 'ok',
            $late ? (int) $cron['overdue'] . ' lavori in ritardo da oltre ' . (int) floor((time() - $oldest) / HOUR_IN_SECONDS) . ' ore.' : 'Lavori programmati eseguiti regolarmente.');
        if ($late) {
            $issues[] = self::issue('server', 'cron', 'WP-Cron', 'medium', 'agenzia', 'WP-Cron non esegue i lavori programmati',
                (int) $cron['overdue'] . ' lavori in ritardo: aggiornamenti automatici, backup e invii di Sentinel non partono.',
                !empty($cron['disabled']) ? 'DISABLE_WP_CRON è attivo: verificare il cron di sistema sul server.' : 'Verificare la chiamata a wp-cron.php (loopback) o configurare un cron di sistema.', 14);
        }

        // Site Health di WordPress.
        $health = $env['health'] ?? null;
        if (is_array($health)) {
            $critical = (int) ($health['critical'] ?? 0);
            $checks[] = self::check('health', 'Salute del sito', $critical ? $critical . ' critici' : 'Nessun problema critico', $critical ? 'warn' : 'ok',
                (int) ($health['recommended'] ?? 0) . ' miglioramenti consigliati da WordPress.');
            if ($critical) {
                $issues[] = self::issue('server', 'site_health', 'Salute del sito', 'low', 'agenzia', 'Salute del sito: ' . $critical . ' problemi critici',
                    'Il controllo “Salute del sito” di WordPress segnala problemi critici.', 'Aprire Strumenti → Salute del sito sul sito.', 6);
            }
        }

        // Backup.
        $backup = $env['backup'] ?? null;
        $backup_plugins = array_values(array_intersect(self::backup_slugs(), array_keys($plugins)));
        $active_backup = array();
        foreach ($backup_plugins as $slug) { if (!empty($plugins[$slug]['active'])) { $active_backup[] = (string) ($plugins[$slug]['name'] ?? $slug); } }
        $max_days = max(1, (int) ($policies['backup_max_days'] ?? 7));
        if (is_array($backup) && !empty($backup['last'])) {
            $age = (int) floor((time() - (int) $backup['last']) / DAY_IN_SECONDS);
            $ok = !empty($backup['ok']);
            $tone = !$ok ? 'danger' : ($age > $max_days ? 'warn' : 'ok');
            $checks[] = self::check('backup', 'Backup', ($ok ? 'Ultimo ' : 'Fallito ') . wp_date('d/m/Y', (int) $backup['last']), $tone, 'UpdraftPlus · ' . ($age === 0 ? 'oggi' : $age . ' giorni fa') . '.');
            if (!$ok || $age > $max_days) {
                $issues[] = self::issue('backup', 'backup_age', 'Backup', $ok ? 'medium' : 'high', 'agenzia', $ok ? 'Ultimo backup di ' . $age . ' giorni fa' : 'L’ultimo backup non è riuscito',
                    'Prima di aggiornare serve un backup recente e completo.', 'Eseguire un backup completo e verificarne la riuscita.', $ok ? 10 : 25);
            }
        } elseif ($active_backup) {
            $checks[] = self::check('backup', 'Backup', implode(', ', array_slice($active_backup, 0, 2)), 'ok', 'Plugin di backup attivo; data dell’ultimo backup non leggibile.');
        } elseif (!empty($meta['hosting_backup'])) {
            $checks[] = self::check('backup', 'Backup', 'Gestito dall’hosting', 'ok', 'Indicato nella checklist di manutenzione.');
        } elseif (Integra_Sentinel_Checklist::has_backup($meta)) {
            $checks[] = self::check('backup', 'Backup', 'Indicato nella checklist', 'ok', 'Nessun plugin di backup sul sito: backup dichiarato nella checklist di manutenzione.');
        } else {
            $checks[] = self::check('backup', 'Backup', 'Non rilevato', 'warn', 'Nessun plugin di backup attivo.');
            $issues[] = self::issue('backup', 'backup_missing', 'Backup', 'medium', 'agenzia', 'Nessun backup rilevato',
                'Nessun plugin di backup attivo e nessun backup indicato nella checklist di manutenzione.',
                'Attivare un backup automatico, oppure segnare nella checklist di manutenzione i backup già attivi (per esempio quelli dell’hosting).', 12);
        }

        // Aggiornamenti automatici e cache oggetti (informativi).
        $auto = (array) ($env['auto_updates'] ?? array());
        $core_auto = (string) ($auto['core'] ?? '');
        $auto_count = count((array) ($auto['plugins'] ?? array()));
        $core_labels = array('all' => 'core: tutte le versioni', 'minor' => 'core: solo minori', 'off' => 'core: disattivati');
        $checks[] = self::check('auto', 'Aggiornamenti automatici', $auto_count . ' plugin', $core_auto === 'off' ? 'warn' : 'ok', ($core_labels[$core_auto] ?? ('core: ' . ($core_auto ?: 'n.d.'))) . '.');

        // Lavori rimasti dopo un aggiornamento.
        $pending = (array) ($env['pending'] ?? array());
        $pending_labels = array();
        if (!empty($pending['wc_db'])) {
            $pending_labels[] = 'database WooCommerce';
            $issues[] = self::issue('postupdate', 'wc_db', 'Database WooCommerce', 'medium', 'agenzia', 'Aggiornamento del database WooCommerce in sospeso',
                'Database alla ' . ($pending['wc_db']['db'] ?? '?') . ', codice alla ' . ($pending['wc_db']['code'] ?? '?') . ': alcune funzioni del negozio possono non funzionare.',
                'Eseguire l’aggiornamento del database da WooCommerce, dopo un backup.', 16);
        }
        if (!empty($pending['elementor_db'])) {
            $pending_labels[] = 'dati Elementor';
            $issues[] = self::issue('postupdate', 'elementor_db', 'Dati Elementor', 'low', 'agenzia', 'Aggiornamento dei dati Elementor in sospeso',
                'Dati alla ' . ($pending['elementor_db']['db'] ?? '?') . ', plugin alla ' . ($pending['elementor_db']['code'] ?? '?') . '.',
                'Aprire la bacheca di WordPress per completare l’aggiornamento dei dati Elementor.', 6);
        }
        $templates = (array) ($pending['wc_templates'] ?? array());
        if ($templates) {
            $pending_labels[] = count($templates) . ' template WooCommerce';
            $names = array();
            foreach (array_slice($templates, 0, 4) as $t) { $names[] = ($t['file'] ?? '?') . ' (' . (($t['theme'] ?? '') ?: '?') . ' → ' . ($t['core'] ?? '?') . ')'; }
            $issues[] = self::issue('postupdate', 'wc_templates', 'Template WooCommerce', 'medium', 'agenzia', count($templates) . ' template WooCommerce del tema sono obsoleti',
                'Il tema sovrascrive template più vecchi di quelli di WooCommerce: ' . implode('; ', $names) . '.',
                'Allineare i template nel tema (meglio nel child theme) prima o insieme all’aggiornamento di WooCommerce.', 18);
        }
        if ($pending_labels) { $checks[] = self::check('pending', 'Dopo l’aggiornamento', 'Da completare', 'warn', implode(', ', $pending_labels) . '.'); }

        // Envato Market senza token mentre ci sono prodotti Envato.
        if (!empty($ctx['has_envato']) && isset($env['envato_market']) && empty($env['envato_market']['token'])) {
            $issues[] = self::issue('license', 'envato-market-token', 'Envato Market', 'low', 'agenzia', 'Envato Market installato senza token',
                'I prodotti Envato del sito non ricevono aggiornamenti automatici.', 'Inserire nel plugin Envato Market il token dell’account che possiede le licenze.', 6);
        }

        return array('checks' => $checks, 'issues' => $issues, 'available' => true);
    }

    private static function mb($mb) {
        $mb = (int) $mb;
        return $mb >= 1024 ? number_format_i18n($mb / 1024, 1) . ' GB' : $mb . ' MB';
    }
}
