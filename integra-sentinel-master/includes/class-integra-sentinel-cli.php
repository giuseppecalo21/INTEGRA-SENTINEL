<?php
/**
 * Integra Sentinel — comandi WP-CLI del Master, utili con un cron di sistema (orari puntuali anche
 * senza traffico sul Master) e per diagnosticare. Nessun comando agisce sui siti dei clienti.
 */

if (!defined('ABSPATH')) { exit; }

final class Integra_Sentinel_CLI {

    public static function register() {
        WP_CLI::add_command('sentinel', __CLASS__);
    }

    /**
     * Esegue in un colpo solo i lavori periodici: audit vulnerabilità, sonde di raggiungibilità, fonti esterne e lavori giornalieri.
     *
     * Consigliato nel cron di sistema ogni 5 minuti: `wp sentinel tick`.
     */
    public function tick() {
        Integra_Sentinel_Master::audit();
        Integra_Sentinel_Master::run_probes();
        if (wp_next_scheduled(Integra_Sentinel_Master::HOOK_ALERTS) && wp_next_scheduled(Integra_Sentinel_Master::HOOK_ALERTS) <= time()) {
            Integra_Sentinel_Master::dispatch_alerts();
        }
        WP_CLI::success('Lavori periodici eseguiti.');
    }

    /**
     * Interroga i database di vulnerabilità per i componenti in scadenza e ricalcola i risultati.
     */
    public function audit() {
        Integra_Sentinel_Master::audit();
        WP_CLI::success('Audit completato (a lotti: ripeti per coprire tutto il parco).');
    }

    /**
     * Controlla subito la raggiungibilità dei siti (home e pagine chiave), in sola lettura.
     *
     * [--site=<id>]
     * : Solo il sito indicato.
     */
    public function probe($args, $assoc) {
        $site = isset($assoc['site']) ? (string) $assoc['site'] : null;
        $results = Integra_Sentinel_Probe::run($site, $site !== null);
        $sites = Integra_Sentinel_Master::sites();
        foreach ($results as $id => $pages) {
            foreach ($pages as $idx => $r) {
                WP_CLI::line(sprintf('%s #%d: %s · HTTP %d · %d ms%s', $sites[$id]['label'] ?? $id, $idx, $r['ok'] ? 'OK' : 'PROBLEMA', $r['code'], $r['ms'], $r['ok'] ? '' : ' · ' . $r['error']));
            }
        }
        WP_CLI::success(count($results) . ' siti controllati.');
    }

    /**
     * Aggiorna le fonti esterne del Master: WordPress.org (plugin chiusi o abbandonati, checksum) ed Envato.
     */
    public function intel() {
        Integra_Sentinel_Intel::tick(60.0);
        WP_CLI::success('Fonti esterne aggiornate (a lotti).');
    }

    /**
     * Invia il report giornaliero adesso.
     *
     * [--test]
     * : Invia l'anteprima marcata come prova.
     */
    public function digest($args, $assoc) {
        if (!empty($assoc['test'])) {
            Integra_Sentinel_Master::send_test_digest() ? WP_CLI::success('Report di prova inviato.') : WP_CLI::error('Invio non riuscito: controlla wp_mail/SMTP.');
            return;
        }
        Integra_Sentinel_Master::send_daily_digest();
        WP_CLI::success('Report giornaliero elaborato (non viene reinviato se già spedito oggi).');
    }

    /**
     * Esegue i lavori giornalieri: metriche per gli andamenti, pulizia dell'archivio, bozze automatiche.
     */
    public function daily() {
        Integra_Sentinel_Jobs::maybe_daily(true);
        WP_CLI::success('Lavori giornalieri eseguiti.');
    }

    /**
     * Elenca i siti con stato e numeri principali.
     *
     * [--format=<format>]
     * : table, csv, json. Predefinito: table.
     */
    public function sites($args, $assoc) {
        $fleet = Integra_Sentinel_Fleet::aggregate();
        $rows = array();
        foreach (Integra_Sentinel_Master::sites() as $id => $site) {
            $s = $fleet['per_site'][$id]['summary'] ?? array();
            $rows[] = array('id' => $id, 'sito' => $site['label'], 'url' => $site['url'],
                'ultimo_report' => !empty($site['last_seen']) ? wp_date('Y-m-d H:i', (int) $site['last_seen']) : 'mai',
                'wp' => $site['report']['wp'] ?? '', 'php' => $site['report']['php'] ?? '',
                'aggiornabili' => $s['ready'] ?? 0, 'bloccati' => $s['blocked'] ?? 0, 'colli_di_bottiglia' => $s['bottlenecks'] ?? 0, 'licenze' => $s['license'] ?? 0);
        }
        WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, array_keys($rows[0] ?? array('id' => '', 'sito' => '')));
    }

    /**
     * Elenca i colli di bottiglia del parco, uno per riga e per sito.
     *
     * [--format=<format>]
     * : table, csv, json. Predefinito: table.
     */
    public function bottlenecks($args, $assoc) {
        $fleet = Integra_Sentinel_Fleet::aggregate();
        $rows = array();
        foreach ($fleet['kinds'] as $kind => $items) {
            foreach ($items as $b) {
                $rows[] = array('sito' => $b['site_label'], 'causa' => Integra_Sentinel_Fleet::kind_label($kind), 'componente' => $b['subjectName'], 'gravita' => $b['severity'], 'problema' => $b['title']);
            }
        }
        if (!$rows) { WP_CLI::success('Nessun collo di bottiglia.'); return; }
        WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, array_keys($rows[0]));
    }
}
