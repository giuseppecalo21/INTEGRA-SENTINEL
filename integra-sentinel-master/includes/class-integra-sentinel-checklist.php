<?php
/**
 * Integra Sentinel — checklist di manutenzione di ogni sito: accessi, backup e accordi da avere
 * prima di aggiornare temi e plugin a mano. Compilata dall'agenzia sul Master, mai inviata al Client.
 *
 * Qui non si salvano credenziali: nelle note si scrive solo dove sono custodite.
 * Le note restano interne; nei documenti per il cliente compare solo lo stato di ogni voce.
 */

if (!defined('ABSPATH')) { exit; }

final class Integra_Sentinel_Checklist {

    private static function e($value) { return esc_html((string) $value); }

    /** Gruppi e voci. "hosting" e' il nome del fornitore (campo della scheda): verde quando e' compilato. */
    public static function groups() {
        return array(
            'access' => array('label' => 'Hosting e accessi', 'items' => array(
                'hosting' => array('Dove è ospitato il sito', 'Fornitore e piano di hosting.'),
                'hosting_account' => array('Area clienti del fornitore di hosting', 'Rinnovi, fatture, ticket all’assistenza e ripristino dei backup dell’hosting.'),
                'panel' => array('Pannello di gestione (cPanel, Plesk o analoghi)', 'File manager, database, versione di PHP, certificati, caselle email.'),
                'ftp' => array('Accesso FTP o SFTP', 'Indispensabile quando un aggiornamento manda il sito in errore e la bacheca non si apre.'),
                'database' => array('Accesso al database', 'phpMyAdmin o credenziali dirette, per ripristini e correzioni.'),
                'wp_admin' => array('Utente amministratore WordPress dell’agenzia', 'Nominativo e non condiviso, così ogni intervento è tracciabile.'),
                'domain' => array('Dominio: intestatario, registrar e scadenza', 'Un dominio scaduto spegne sito ed email insieme.'),
                'dns' => array('Gestione dei DNS', 'Dove sono (hosting, registrar, Cloudflare) e chi può modificarli: servono per migrazioni, certificati ed email.'),
            )),
            'backup' => array('label' => 'Backup', 'items' => array(
                'backup_daily' => array('Backup giornaliero', ''),
                'backup_weekly' => array('Backup settimanale', ''),
                'backup_monthly' => array('Backup mensile', ''),
                'backup_offsite' => array('Copia dei backup fuori dal server', 'Se il server si guasta o viene compromesso, i backup salvati sullo stesso server si perdono con lui.'),
                'backup_restore' => array('Ripristino provato', 'Almeno una volta, su una copia di prova: finché non lo si ripristina, un backup non dà garanzie.'),
            )),
            'updates' => array('label' => 'Aggiornamenti', 'items' => array(
                'theme' => array('Tema aggiornabile', 'Si aggiorna senza perdere modifiche: child theme, oppure nessuna personalizzazione nei file del tema.'),
                'licenses' => array('Licenze di plugin e tema premium', 'Chiavi disponibili e intestatario noto, per poter scaricare gli aggiornamenti.'),
                'staging' => array('Copia di prova (staging)', 'Disponibile o creabile, per provare gli aggiornamenti delicati prima del sito vero.'),
            )),
            'agreements' => array('label' => 'Accordi', 'items' => array(
                'gdpr' => array('Nomina a responsabile del trattamento (art. 28 GDPR)', 'Con gli accessi al sito e al database l’agenzia tratta dati personali per conto del cliente: serve un accordo scritto.'),
            )),
        );
    }

    public static function items() {
        $out = array();
        foreach (self::groups() as $group) { $out += $group['items']; }
        return $out;
    }

    /** Valori ammessi: '' = da verificare. */
    public static function states() {
        return array('' => 'Da verificare', 'yes' => 'Sì, a posto', 'no' => 'No, manca', 'na' => 'Non serve');
    }

    /** Stato di ogni voce: s (yes|no|na|''), note, at, by. */
    public static function data($site) {
        $meta = (array) ($site['meta'] ?? array());
        $saved = (array) ($meta['checklist'] ?? array());
        $out = array();
        foreach (array_keys(self::items()) as $key) {
            $row = (array) ($saved[$key] ?? array());
            $out[$key] = array('s' => (string) ($row['s'] ?? ''), 'note' => (string) ($row['note'] ?? ''), 'at' => (int) ($row['at'] ?? 0), 'by' => (int) ($row['by'] ?? 0));
        }
        // Il fornitore di hosting e' un campo della scheda: la voce e' a posto quando e' compilato.
        $out['hosting']['s'] = trim((string) ($meta['hosting'] ?? '')) !== '' ? 'yes' : '';
        $out['hosting']['note'] = (string) ($meta['hosting'] ?? '');
        return $out;
    }

    /**
     * Riepilogo: total (voci che servono), ok, missing, todo, tone (ok|warn|danger|muted), text, missing_labels.
     * "Non serve" non conta. Una checklist mai toccata e' "da compilare".
     */
    public static function summary($site) {
        $items = self::items();
        $data = self::data($site);
        $ok = 0; $missing = 0; $todo = 0; $total = 0; $touched = false; $missing_labels = array();
        foreach ($data as $key => $row) {
            if ($row['s'] !== '' || $row['at']) { $touched = true; }
            if ($row['s'] === 'na') { continue; }
            ++$total;
            if ($row['s'] === 'yes') { ++$ok; }
            elseif ($row['s'] === 'no') { ++$missing; $missing_labels[] = $items[$key][0]; }
            else { ++$todo; }
        }
        if (!$touched) { return array('total' => $total, 'ok' => 0, 'missing' => 0, 'todo' => $total, 'tone' => 'muted', 'text' => 'Da compilare', 'missing_labels' => array()); }
        $parts = array();
        if ($missing) { $parts[] = $missing . ($missing === 1 ? ' voce mancante' : ' voci mancanti'); }
        if ($todo) { $parts[] = $todo . ' da verificare'; }
        return array('total' => $total, 'ok' => $ok, 'missing' => $missing, 'todo' => $todo,
            'tone' => $missing ? 'danger' : ($todo ? 'warn' : 'ok'), 'text' => $parts ? implode(', ', $parts) : 'Tutto a posto', 'missing_labels' => $missing_labels);
    }

    /** Il cliente vede la checklist nel report mensile salvo scelta contraria. */
    public static function in_monthly($site) {
        $meta = (array) ($site['meta'] ?? array());
        return !isset($meta['checklist_report']) || !empty($meta['checklist_report']);
    }

    /** Backup indicati nella checklist (giornaliero o settimanale): per il controllo dell'ambiente. */
    public static function has_backup($meta) {
        $c = (array) ($meta['checklist'] ?? array());
        return ($c['backup_daily']['s'] ?? '') === 'yes' || ($c['backup_weekly']['s'] ?? '') === 'yes';
    }

    /** Salva hosting, link al pannello e voci; data e autore cambiano solo sulle voci modificate. */
    public static function save($id, $input) {
        $hosting = isset($input['hosting']) ? substr(sanitize_text_field(wp_unslash($input['hosting'])), 0, 80) : '';
        $panel = Integra_Sentinel_Master::safe_link(isset($input['panel_url']) ? (string) wp_unslash($input['panel_url']) : '');
        $states = isset($input['cl_state']) ? (array) wp_unslash($input['cl_state']) : array();
        $notes = isset($input['cl_note']) ? (array) wp_unslash($input['cl_note']) : array();
        $fields = array('hosting' => $hosting, 'panel_url' => $panel, 'hosting_backup' => !empty($input['hosting_backup']), 'checklist_report' => !empty($input['checklist_report']));
        $user = get_current_user_id();
        return Integra_Sentinel_Master::mutate_sites(function($current) use ($id, $fields, $states, $notes, $user) {
            if (!isset($current[$id])) { return null; }
            $meta = (array) ($current[$id]['meta'] ?? array());
            $old = (array) ($meta['checklist'] ?? array());
            $list = array();
            foreach (array_keys(self::items()) as $key) {
                if ($key === 'hosting') { continue; }
                $s = (string) ($states[$key] ?? '');
                if (!isset(self::states()[$s])) { $s = ''; }
                $note = substr(sanitize_text_field((string) ($notes[$key] ?? '')), 0, 160);
                $prev = (array) ($old[$key] ?? array());
                $changed = $s !== (string) ($prev['s'] ?? '') || $note !== (string) ($prev['note'] ?? '');
                if ($s === '' && $note === '' && !$prev) { continue; }
                $list[$key] = array('s' => $s, 'note' => $note, 'at' => $changed ? time() : (int) ($prev['at'] ?? 0), 'by' => $changed ? $user : (int) ($prev['by'] ?? 0));
            }
            $hosting_at = (array) ($old['hosting'] ?? array());
            if ($fields['hosting'] !== (string) ($meta['hosting'] ?? '')) { $hosting_at = array('at' => time(), 'by' => $user); }
            if ($hosting_at) { $list['hosting'] = array('at' => (int) ($hosting_at['at'] ?? 0), 'by' => (int) ($hosting_at['by'] ?? 0)); }
            $current[$id]['meta'] = array_merge($meta, $fields, array('checklist' => $list));
            return $current;
        });
    }

    /** Cosa ne sa Sentinel dai dati del sito, accanto alle voci che puo' confrontare. */
    private static function hints($analysis) {
        $out = array();
        if (!$analysis) { return $out; }
        foreach ((array) ($analysis['environment']['checks'] ?? array()) as $c) {
            if ($c['key'] === 'backup') {
                $out['group_backup'] = 'Sentinel rileva: ' . $c['value'] . ($c['hint'] !== '' ? ' (' . rtrim($c['hint'], '.') . ')' : '') . '.';
            }
        }
        foreach ((array) ($analysis['themes']['themes'] ?? array()) as $t) {
            if ($t['role'] !== 'active') { continue; }
            $notes = array();
            if ($t['customized']) { $notes[] = 'functions.php diverso dagli altri siti con la stessa versione (probabile personalizzazione)'; }
            if ($t['noChild']) { $notes[] = 'nessun child theme'; }
            if (in_array($t['status'], array('license', 'blocked', 'manual', 'blind'), true) && $t['reasons']) { $notes[] = rtrim((string) $t['reasons'][0], '.'); }
            $out['theme'] = 'Sentinel rileva: ' . $t['name'] . ($t['parent'] !== '' ? ' (child theme)' : '') . ' · ' . $t['originLabel'] . ($notes ? ' · ' . implode('; ', $notes) : '') . '.';
        }
        $licenses = 0;
        foreach ((array) ($analysis['bottlenecks'] ?? array()) as $b) { if ($b['kind'] === 'license') { ++$licenses; } }
        if ($licenses) { $out['licenses'] = 'Sentinel rileva ' . $licenses . ($licenses === 1 ? ' problema di licenza' : ' problemi di licenza') . ' tra le cose che bloccano gli aggiornamenti.'; }
        return $out;
    }

    private static function stamp($row) {
        if (empty($row['at'])) { return ''; }
        $user = !empty($row['by']) ? get_userdata((int) $row['by']) : false;
        return 'Aggiornato il ' . wp_date('d/m/Y', (int) $row['at']) . ($user ? ' da ' . $user->display_name : '');
    }

    /** Pannello della scheda sito. */
    public static function panel($site, $analysis = null) {
        $meta = (array) ($site['meta'] ?? array());
        $data = self::data($site);
        $sum = self::summary($site);
        $hints = self::hints($analysis);
        $badge = Integra_Sentinel_Master::sn_badge($sum['tone'] === 'muted' ? $sum['text'] : $sum['ok'] . '/' . $sum['total'] . ' a posto', $sum['tone']);
        echo '<details class="sn-panel sn-config sn-checklist" id="sn-checklist"' . ($sum['tone'] === 'danger' ? ' open' : '') . '><summary><span><strong>Checklist di manutenzione ' . $badge . '</strong><small>Accessi, backup e accordi da avere prima di aggiornare a mano temi e plugin' . ($sum['tone'] !== 'muted' && $sum['tone'] !== 'ok' ? ' · ' . self::e($sum['text']) : '') . '</small></span><span class="sn-chevron">⌄</span></summary><div class="sn-config-body">';
        $manage = Integra_Sentinel_Settings::can_manage();
        if ($manage) { Integra_Sentinel_Admin::form_start('site_checklist', 'integra-sentinel', array('site_id' => $site['id'], '_sn_site' => $site['id'], '_sn_anchor' => 'sn-checklist')); }
        echo '<p class="sn-hint">Niente password qui: nelle note scrivi dove sono custodite (per esempio la cartella del gestore di password). Le note restano interne; nei documenti per il cliente compare solo lo stato di ogni voce.</p>';
        foreach (self::groups() as $gkey => $group) {
            echo '<fieldset class="sn-cl-group"><legend>' . self::e($group['label']) . '</legend>';
            if (isset($hints['group_' . $gkey])) { echo '<p class="sn-cl-hint">' . self::e($hints['group_' . $gkey]) . '</p>'; }
            foreach ($group['items'] as $key => $item) {
                $row = $data[$key];
                $tone = array('yes' => 'ok', 'no' => 'danger', 'na' => 'muted', '' => 'warn')[$row['s']] ?? 'warn';
                echo '<div class="sn-cl-row sn-check-' . esc_attr($tone) . '"><div class="sn-cl-label"><strong>' . self::e($item[0]) . '</strong>';
                if ($item[1] !== '') { echo '<small>' . self::e($item[1]) . '</small>'; }
                if (isset($hints[$key])) { echo '<small class="sn-cl-hint">' . self::e($hints[$key]) . '</small>'; }
                $stamp = self::stamp($row);
                if ($stamp !== '') { echo '<small class="sn-cl-stamp">' . self::e($stamp) . '</small>'; }
                echo '</div><div class="sn-cl-fields">';
                if ($key === 'hosting') {
                    if ($manage) {
                        echo '<input type="text" name="hosting" maxlength="80" value="' . esc_attr($meta['hosting'] ?? '') . '" placeholder="es. Aruba, SiteGround, server dedicato" aria-label="Fornitore di hosting">';
                        echo '<input type="url" name="panel_url" value="' . esc_attr($meta['panel_url'] ?? '') . '" placeholder="Link al pannello (mai credenziali)" aria-label="Link al pannello dell’hosting">';
                    } else {
                        echo '<span>' . self::e(($meta['hosting'] ?? '') !== '' ? $meta['hosting'] : 'Non indicato') . '</span>';
                    }
                } elseif ($manage) {
                    echo '<select name="cl_state[' . esc_attr($key) . ']" aria-label="' . esc_attr($item[0]) . '">';
                    foreach (self::states() as $value => $label) { echo '<option value="' . esc_attr($value) . '"' . selected($row['s'], $value, false) . '>' . self::e($label) . '</option>'; }
                    echo '</select><input type="text" name="cl_note[' . esc_attr($key) . ']" maxlength="160" value="' . esc_attr($row['note']) . '" placeholder="Nota interna (facoltativa)" aria-label="Nota: ' . esc_attr($item[0]) . '">';
                } else {
                    echo Integra_Sentinel_Master::sn_badge(self::states()[$row['s']] ?? 'Da verificare', $tone) . ($row['note'] !== '' ? ' <span>' . self::e($row['note']) . '</span>' : '');
                }
                echo '</div></div>';
            }
            if ($gkey === 'backup' && $manage) {
                echo '<label class="sn-inline-check"><input type="checkbox" name="hosting_backup" value="1"' . checked(!empty($meta['hosting_backup']), true, false) . '> I backup li fa l’hosting (vale anche per il controllo “Backup” di Sentinel)</label>';
            }
            echo '</fieldset>';
        }
        if ($manage) {
            echo '<label class="sn-inline-check"><input type="checkbox" name="checklist_report" value="1"' . checked(self::in_monthly($site), true, false) . '> Mostra lo stato della checklist nel report mensile per il cliente (l’audit di presa in carico la mostra sempre)</label>';
            echo '<p>';
            submit_button('Salva la checklist', 'primary', 'submit', false);
            echo '</p></form>';
        }
        echo '</div></details>';
    }

    /** Tabella per i documenti del cliente: solo stato, niente note interne. Le voci "non serve" non compaiono. */
    public static function document_html($site, $intro) {
        $sum = self::summary($site);
        if ($sum['tone'] === 'muted') { return ''; }
        $data = self::data($site);
        $labels = array('yes' => array('Verificato', 'ok'), 'no' => array('Da sistemare', 'bad'), '' => array('Da verificare', 'warn'));
        $html = '<h2>Prerequisiti per la manutenzione</h2><p class="sub">' . esc_html($intro) . '</p><table><tbody>';
        foreach (self::groups() as $group) {
            $rows = '';
            foreach ($group['items'] as $key => $item) {
                $s = $data[$key]['s'];
                if ($s === 'na') { continue; }
                $value = $key === 'hosting' && $s === 'yes' ? esc_html($data[$key]['note']) : '<span class="pill ' . esc_attr($labels[$s][1]) . '">' . esc_html($labels[$s][0]) . '</span>';
                $rows .= '<tr><td style="width:62%">' . esc_html($item[0]) . '</td><td>' . $value . '</td></tr>';
            }
            if ($rows !== '') { $html .= '<tr><th colspan="2">' . esc_html($group['label']) . '</th></tr>' . $rows; }
        }
        return $html . '</tbody></table>';
    }
}
