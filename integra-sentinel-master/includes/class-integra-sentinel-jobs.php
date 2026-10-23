<?php
/**
 * Integra Sentinel — lavori giornalieri del Master: metriche per gli andamenti, promemoria delle licenze,
 * chiusura automatica degli interventi risolti, data di apertura dei punti aperti, pulizia dell'archivio. Avviati dall'audit orario.
 */

if (!defined('ABSPATH')) { exit; }

final class Integra_Sentinel_Jobs {

    const LAST_DAILY = 'integra_sentinel_last_daily';
    const EMERGENCIES = 'integra_sentinel_emergencies';

    public static function maybe_daily($force = false) {
        $today = wp_date('Y-m-d');
        if (!$force && get_option(self::LAST_DAILY) === $today) { return false; }
        if (!Integra_Sentinel_Master::acquire_lock('daily_jobs', 0)) { return false; }
        try {
            if (!$force && Integra_Sentinel_Master::fresh_option(self::LAST_DAILY, '') === $today) { return false; }
            update_option(self::LAST_DAILY, $today, false);
            Integra_Sentinel_Analysis::reset();
            Integra_Sentinel_Fleet::reset();
            $fleet = Integra_Sentinel_Fleet::aggregate();
            self::snapshot_metrics($today, $fleet);
            self::license_reminders();
            self::auto_resolve_tasks($fleet);
            Integra_Sentinel_Reports::track_open($fleet);
            Integra_Sentinel_Store::cleanup();
            Integra_Sentinel_Reports::maybe_auto_drafts();
            return true;
        } finally {
            Integra_Sentinel_Master::release_lock('daily_jobs');
        }
    }

    public static function snapshot_metrics($day, $fleet) {
        $sites = Integra_Sentinel_Master::sites();
        foreach ($fleet['per_site'] as $id => $a) {
            $s = $a['summary'];
            $site = $sites[$id] ?? array();
            Integra_Sentinel_Store::save_metrics($day, $id, array(
                'updates' => (int) $s['updates'], 'ready' => (int) $s['ready'], 'blocked' => (int) $s['blocked'], 'license' => (int) $s['license'],
                'manual' => (int) $s['manual'], 'blind' => (int) $s['blind'], 'bottlenecks' => (int) $s['bottlenecks'], 'critical' => (int) $s['critical'],
                'vulns' => count(Integra_Sentinel_Master::sn_group_findings($site['findings'] ?? array())),
                'integrity' => count((array) ($site['integrity_findings'] ?? array())), 'minutes' => (int) $s['minutes'],
            ));
        }
    }

    /** Avvisi a 30 e 7 giorni dalla scadenza e il giorno dopo la scadenza; ogni soglia una sola volta. */
    public static function license_reminders() {
        $policies = Integra_Sentinel_Settings::get('policies');
        if (empty($policies['license_reminders'])) { return; }
        $sites = Integra_Sentinel_Master::sites();
        $usage = Integra_Sentinel_Licenses::usage($sites);
        foreach (Integra_Sentinel_Licenses::expiring(30) as $entry) {
            $threshold = $entry['days'] < 0 ? 'expired' : ($entry['days'] <= 7 ? '7' : '30');
            if (in_array($threshold, (array) ($entry['reminded'] ?? array()), true)) { continue; }
            $used = (array) ($usage[$entry['id']]['sites'] ?? array());
            $when = $entry['days'] < 0 ? 'scaduta il ' . wp_date('d/m/Y', strtotime($entry['expires'] . ' 12:00:00')) : 'scade tra ' . $entry['days'] . ' giorni (' . wp_date('d/m/Y', strtotime($entry['expires'] . ' 12:00:00')) . ')';
            $title = 'Licenza ' . $entry['product'] . ' ' . $when;
            $text = 'Usata su ' . count($used) . ' siti' . ($used ? ': ' . implode(', ', array_slice($used, 0, 8)) . (count($used) > 8 ? '…' : '') : '') . '. '
                . ($entry['cost'] ? 'Costo indicato: ' . number_format_i18n((float) $entry['cost'], 2) . ' € · ' : '') . 'Paga: ' . ($entry['payer'] === 'cliente' ? 'cliente' : 'agenzia') . '.';
            $url = admin_url('admin.php?page=integra-sentinel-licenses');
            $body = Integra_Sentinel_Master::mail_notice($entry['days'] < 0 ? 'LICENZA SCADUTA' : 'LICENZA IN SCADENZA', $text, $entry['days'] <= 7 ? 'danger' : 'warn')
                . (!empty($entry['vendor_url']) ? Integra_Sentinel_Master::mail_cta('Rinnova dal fornitore', $entry['vendor_url']) : '')
                . Integra_Sentinel_Master::mail_cta('Apri il registro licenze', $url);
            $mail = Integra_Sentinel_Master::send_html('[Integra Sentinel] ' . $title, Integra_Sentinel_Master::mail_shell($title, 'Registro licenze dell’agenzia', $body, $entry['days'] <= 7 ? 'danger' : 'warn'));
            $hook = Integra_Sentinel_Notify::send('license', $title, $text, $url);
            if ($mail || $hook) { Integra_Sentinel_Licenses::mark_reminded($entry['id'], $threshold); }
        }
    }

    /** Un intervento collegato a un collo di bottiglia che non esiste piu' si chiude da solo, con nota. */
    public static function auto_resolve_tasks($fleet) {
        foreach (Integra_Sentinel_Store::tasks(array('status' => 'active', 'limit' => 1000)) as $task) {
            if ($task['kind'] === '' || $task['subject'] === '' || $task['site_id'] === '' || !isset($fleet['per_site'][$task['site_id']])) { continue; }
            $a = $fleet['per_site'][$task['site_id']];
            $still = false;
            foreach (array_merge($a['bottlenecks'], $a['waived']) as $b) {
                if ($b['kind'] === $task['kind'] && (string) $b['subject'] === (string) $task['subject']) { $still = true; break; }
            }
            if (!$still) {
                Integra_Sentinel_Store::task_set_status((int) $task['id'], 'done', 'Chiuso automaticamente: il problema non risulta più nei dati del sito.');
            }
        }
    }

    /**
     * Emergenza: nuove vulnerabilita' critiche o alte sullo stesso componente in piu' siti → un solo avviso
     * con la vista per prodotto, oltre agli alert dei singoli siti.
     * @param array $fresh site_id => findings nuovi confermati
     * @param bool  $mail  false se gli alert email sono disattivati (restano i webhook)
     */
    public static function emergency($fresh, $sites, $mail = true) {
        $by_component = array();
        foreach ($fresh as $site_id => $findings) {
            foreach (Integra_Sentinel_Master::sn_group_findings($findings) as $g) {
                if (!in_array($g['max_tier'], array('critical', 'high'), true)) { continue; }
                $by_component[$g['key']]['name'] = $g['name'];
                $by_component[$g['key']]['type'] = $g['type'];
                $by_component[$g['key']]['slug'] = $g['slug'];
                $by_component[$g['key']]['tier'] = $g['max_tier'];
                $by_component[$g['key']]['sites'][$site_id] = (string) ($sites[$site_id]['label'] ?? $site_id);
                foreach ($g['items'] as $f) { $by_component[$g['key']]['ids'][(string) ($f['id'] ?? '')] = true; }
            }
        }
        $sent_log = get_option(self::EMERGENCIES, array());
        $sent_log = is_array($sent_log) ? $sent_log : array();
        foreach ($by_component as $key => $c) {
            if (count($c['sites']) < 2) { continue; }
            $signature = md5($key . '|' . implode(',', array_keys($c['ids'])));
            if (isset($sent_log[$signature])) { continue; }
            $url = admin_url('admin.php?page=integra-sentinel-fleet&product=' . rawurlencode($c['type'] . ':' . $c['slug']));
            $title = 'Emergenza: ' . $c['name'] . ' vulnerabile su ' . count($c['sites']) . ' siti';
            $text = 'Siti coinvolti: ' . implode(', ', array_slice($c['sites'], 0, 12)) . (count($c['sites']) > 12 ? '…' : '') . '. Gravità massima: ' . Integra_Sentinel_Master::sn_severity($c['tier']) . '.';
            $body = Integra_Sentinel_Master::mail_notice('MODALITÀ EMERGENZA', $text . ' Apri la vista per prodotto per vedere dove si può aggiornare subito e dove ci sono blocchi.', 'danger')
                . Integra_Sentinel_Master::mail_cta('Apri la vista ' . $c['name'], $url);
            $ok = $mail && Integra_Sentinel_Master::send_html('[Integra Sentinel] ' . $title, Integra_Sentinel_Master::mail_shell($title, 'Stessa vulnerabilità su più siti del parco', $body, 'danger'));
            $ok = Integra_Sentinel_Notify::send('emergency', $title, $text, $url) || $ok;
            if ($ok) { $sent_log[$signature] = time(); }
        }
        update_option(self::EMERGENCIES, array_slice($sent_log, -300, null, true), false);
    }
}
