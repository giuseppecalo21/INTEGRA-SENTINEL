=== Integra Sentinel Master ===
Contributors: integra
Tags: wordpress, vulnerability, monitoring, security, updates
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 1.9.1
License: GPLv2 or later

Monitoraggio centralizzato degli aggiornamenti e delle vulnerabilita di siti WordPress.
Author: Integra | https://integracorporate.it/

Principio: il Master e' in SOLA LETTURA verso i siti dei clienti. Non installa, non aggiorna e non modifica nulla:
riceve i report firmati dei Client, consulta fonti esterne e documenta. Tutto il lavoro pesante resta sul Master.

== 1.9.1 - 24 October 2026 ==
* Nuova pagina Storico: tutti gli aggiornamenti, le installazioni e le rimozioni di tutti i siti, mese per mese (si apre sul mese in corso), filtrabili per sito. Per ogni cambiamento si puo' scrivere il motivo, che entra nel resoconto per il cliente e nella tabella del report mensile.
* Avviso nello Storico per i siti che non inviano l'inventario da piu' di 36 ore (i loro aggiornamenti non possono essere registrati).
* Scheda del sito: "Ultimi cambiamenti" sempre visibile, al posto dello storico nascosto in fondo alla pagina.
* Report clienti: numero di aggiornamenti registrati per sito nel mese scelto e scorciatoie "Mese scorso" / "Mese in corso"; l'editor del resoconto indica quanti aggiornamenti ci sono e rimanda allo Storico.
* Lo schema del database si aggiorna anche fuori dall'amministrazione (cron, REST, WP-CLI), cosi' le nuove colonne esistono sempre dopo un aggiornamento via zip.

== 1.9.0 - 24 October 2026 ==
* Resoconti per i clienti scritti con l'IA (Report clienti → Prepara il resoconto): note dell'agenzia, bozza scritta dall'IA, rilettura e approvazione, anteprima e invio. Il resoconto approvato apre il report mensile e l'email al cliente; le bozze non approvate non escono mai.
* L'IA riceve solo fatti verificabili: aggiornamenti del mese (con le vulnerabilita' che hanno corretto, ricavate dai feed), aggiornamenti in sospeso con il motivo rilevato da Sentinel o dalle eccezioni approvate, interventi chiusi con le note, disponibilita' e stato tecnico. Mai email, amministratori, credenziali o percorsi dei file. Istruzioni esplicite: nessuna motivazione inventata, italiano naturale, registro voi/Lei/tu a scelta.
* Impostazioni → Intelligenza artificiale: Google Gemini (piano gratuito, modello predefinito gemini-flash-latest), Anthropic Claude o qualsiasi API compatibile OpenAI (OpenAI, Mistral, Groq, OpenRouter). Chiave cifrata, verifica del collegamento ed elenco dei modelli Gemini disponibili. Con il piano gratuito di Gemini Google puo' usare i dati inviati per migliorare i suoi servizi.
* Panoramica semplificata: 4 indicatori invece di 10, un solo elenco "Cosa sistemare per primo", filtri dei siti ridotti a Tutti / Da sistemare / Da verificare / Non collegati.
* Scheda del sito semplificata: in alto vulnerabilita' da correggere e cosa blocca gli aggiornamenti; mappa delle dipendenze, origine dei plugin, server, temi, controlli SEO/SSL/log, gravita' e copertura raccolti in "Dettagli tecnici". Filtri dei plugin ridotti a Tutti / Da sistemare / Da aggiornare / Premium.
* I mesi dei documenti sono sempre in italiano; gli avvisi di WordPress non finiscono piu' dentro l'intestazione delle pagine.
* Nuova tabella per le bozze dei resoconti (creata automaticamente all'aggiornamento). Nessuna modifica richiesta ai Client.

== 1.8.0 - 23 October 2026 ==
* Centro colli di bottiglia nella panoramica: tutto cio' che impedisce di aggiornare, raggruppato per causa e per prodotto ("ACF PRO: licenza scaduta su 6 siti" = un'azione), con gravita', impatto, minuti stimati e chi deve agire (agenzia, cliente, hosting, fornitore). Pagina dedicata con filtri, ricerca, esportazione CSV, interventi ed eccezioni approvate.
* Indicatori di aggiornabilita': aggiornabili subito, bloccati (e quanti trattengono patch di sicurezza), licenze da sistemare, premium da aggiornare a mano, punti ciechi, lavoro stimato.
* Licenze: aggiornamento visibile ma pacchetto non scaricabile (licenza mancante o scaduta) e stato licenza di Elementor Pro, ACF PRO, Gravity Forms, WP Rocket, Divi/Extra (Client 1.7, mai la chiave). Registro licenze dell'agenzia con siti coperti, scadenze, costi, chi paga e promemoria a 30/7 giorni.
* Corretto il "falso verde": un premium senza canale e senza versione di riferimento ora risulta "Versione ignota" (punto cieco) invece di "Allineato".
* Acquisti Envato (token personale cifrato sul Master): ultime versioni dei prodotti ThemeForest/CodeCanyon abbinate per nome, senza catalogo manuale.
* WordPress.org dal Master: plugin chiusi (con motivo) o abbandonati, data di rilascio per la quarantena, checksum ufficiali del file principale dei plugin e dei file del core (anomalia di integrita' verificata, con alert). Confronto delle impronte tra siti del parco per i premium e per functions.php dei temi.
* Server che impedisce gli aggiornamenti: DISALLOW_FILE_MODS, credenziali FTP richieste, cartelle non scrivibili, spazio su disco, memoria, WP-Cron fermo, Site Health; backup rilevato o assente; database WooCommerce/Elementor in sospeso e template WooCommerce obsoleti.
* Fine supporto con date (PHP, MySQL, MariaDB) al posto della soglia fissa PHP 8.2, aggiornabile con il filtro integra_sentinel_eol_table.
* Temi: origine e canale, licenza, child theme assente, personalizzazioni, plugin inclusi (TGMPA), requisiti PHP/WordPress.
* Politiche (solo indicazioni): quarantena delle versioni x.y.0, versioni da evitare, periodi di blocco (es. Black Friday), ondate di aggiornamento, stack standard dell'agenzia con doppioni e plugin inattivi.
* Vista "Plugin e temi del parco" con modalita' emergenza: dove si trova un prodotto, versioni, cosa blocca, ordine per ondate. Email/webhook unico quando la stessa vulnerabilita' colpisce piu' siti.
* Uptime e pagine chiave controllati dal Master come normali visite (sola lettura), con allarme dopo 2 controlli falliti e avviso di ripristino; disattivabile per sito.
* Report mensile white-label e audit di presa in carico stampabili (PDF dal browser), testi pronti per cliente e hosting, scheda cliente (contatti, ondata, tag, hosting, finestra di manutenzione, pagine chiave).
* Interventi assegnabili con stato, scadenza e tempo; chiusura automatica quando il problema sparisce dai dati.
* Notifiche Slack-compatibili, Telegram e webhook JSON (Make, Zapier, n8n), URL e token cifrati.
* Archivio su tabelle dedicate: storico completo (migrazione automatica dalla 1.7), sonde, metriche giornaliere con andamenti a 30 giorni, registro attivita' del Master.
* Ruoli: visione e gestione assegnabili ad altri ruoli; siti, chiavi, token e canali restano agli amministratori. Avviso se manca un plugin 2FA sul Master.
* Privacy degli amministratori: il Client invia solo i nomi utente (le email non lasciano il sito del cliente). Come difesa in profondita' il Master maschera comunque qualsiasi email ricevuta o presente nello storico importato.
* Rigenerazione chiave con tolleranza configurabile (la vecchia chiave resta valida qualche giorno) e revoca immediata.
* Abbinamento dei Client con codice monouso (30 minuti, legato all'URL), anche via WP-CLI.
* Scansione vulnerabilita' in parallelo (fino a 12 componenti per giro invece di 2).
* WP-CLI: wp sentinel tick | audit | probe | intel | digest [--test] | daily | sites | bottlenecks. Consigliato un cron di sistema ogni 5 minuti con "wp sentinel tick".
* Compatibile con Client 1.5/1.6 (funzioni base); licenze, ambiente, temi, checksum e abbinamento richiedono il Client 1.7.0.

== 1.7.0 - 22 October 2026 ==
* Nuova "Mappa aggiornamenti e dipendenze" nella scheda di ogni sito, con tre viste: Nuclei (grafo interattivo), Albero (accessibile e stampabile) e Piano di aggiornamento in fasi.
* Nuclei riconosciuti automaticamente: WooCommerce, Elementor/Elementor Pro, ACF, Gravity Forms, WPML, Contact Form 7, WPBakery, EDD, BuddyPress, LearnDash, Polylang, WPForms, The Events Calendar, Yoast, Rank Math, Beaver Builder, Slider Revolution, MemberPress, Ninja Forms, piu qualsiasi plugin indicato in "Requires Plugins".
* Ogni collegamento ha un livello di evidenza: regola manuale, dipendenza dichiarata, header di compatibilita (WC/Elementor tested up to), firma nel codice (Client 1.6), nome (solo indizio).
* Colli di bottiglia: addon testati solo fino a una versione precedente del nucleo e senza aggiornamenti "trattengono" il nucleo; priorita critica se l'aggiornamento trattenuto corregge vulnerabilita note. Rilevati anche requisiti PHP/WordPress, dipendenze mancanti e addon che richiedono prima l'aggiornamento del nucleo.
* Origine dei plugin: Libreria WordPress.org, premium con updater del fornitore, Envato/CodeCanyon, inclusi nel tema (TGMPA), senza canale, su misura.
* Versioni dei premium senza aggiornamento automatico ricavate da: catalogo Integra (impostazioni), pacchetti inclusi nei temi e versioni viste su altri siti del parco (stesso slug e stesso nome).
* Impostazioni "Dipendenze e catalogo premium": regole manuali addon > nucleo e ultime versioni dei premium.
* Elenco plugin con badge di origine/stato e nuovi filtri "Colli di bottiglia" e "Fuori libreria"; schede siti della panoramica con il numero di colli di bottiglia.
* Nuovi campi del Client validati in whitelist (canale, updater, accoppiamenti, link http/https senza credenziali). Dei link di download premium si conserva solo il dominio.
* Compatibile con Client 1.5.x (analisi basata su header e nome); il rilevamento completo dell'origine richiede Client 1.6.0.
* Grafo in SVG nativo, nessuna libreria esterna; dati passati al JS come JSON non eseguibile.

== 1.4.2 - 14 October 2026 ==
* Corretto il rischio di lost update: inventari, registrazione/rimozione siti, rotazione chiavi, scansioni e conferme degli alert ora si sincronizzano mediante MySQL/MariaDB GET_LOCK e riletture fresche da wp_options.
* I processi con chiamate remote e invio email non trattengono il lock sui dati dei siti; al salvataggio applicano solo i cambiamenti autorizzati. I lock falliscono in modo sicuro se il database non li supporta.
* Anche le cache dei feed sono aggiornate tramite delta protetti: scansioni parallele non cancellano il lavoro altrui.
* Corretto il link agli advisory WordPress Core nella lista delle priorita.
* Ripulito l'hook one-shot di audit alla disattivazione; pulizia dei nonce periodica e indipendente dalla riuscita dell'audit.
* Pianificazione report giornaliero su admin_init, con lock dedicato; invio delle email quotidiane e degli alert protetto dalle esecuzioni parallele.
* Alert inviati sono confermati individualmente, senza cancellare nuovi inventari o nuovi eventi arrivati durante il send.
* WPScan usa il lock DB; le versioni RC/beta di WordPress non vengono trattate come release stabili e l'esclusione e visibile nel dettaglio.
* Rimangono le correzioni v1.4.1 per plugin premium Update URI e digest verde.

== Requisiti di sicurezza operativa ==
* Il database del Master DEVE supportare MySQL/MariaDB advisory locks GET_LOCK/RELEASE_LOCK. Senza supporto le modifiche agli inventari falliscono esplicitamente, anziche rischiare perdite silenziose.
* Eseguire un backup di database e file del Master prima di aggiornare.
* AUTH_KEY e SECURE_AUTH_KEY di WordPress derivano la chiave per cifrare segreti Client e token WPScan. Se vengono rigenerati nel wp-config.php, i vecchi segreti NON sono piu decifrabili: ripristinare le costanti originali oppure rigenerare e aggiornare le chiavi in tutti i Client e il token WPScan.
* WP-Cron non garantisce la puntualita del report alle 08:30 senza un cron server reale; il server email deve essere configurato.
* I lock DB evitano lost updates; non garantiscono consegna esattamente-una-volta della posta in caso di crash di PHP tra invio e conferma di invio.
* Supporto WordPress 6.5+; PHP 7.4+; MySQL/MariaDB compatibile con WordPress e con GET_LOCK.

== 1.4.1 - 14 October 2026 ==
* Update URI non esclude piu i plugin dal monitoraggio WPVulnerability e WPScan.
* Riconoscimento prudente dei plugin premium noti mediante corrispondenza tra slug e nome prodotto.
* Per Update URI non riconosciuti, le corrispondenze API sono segnalazioni potenziali, non vulnerabilita confermate; email di verifica distinte e deduplicate.
* La copertura distingue verifiche API, identita non confermate e componenti interni Sentinel.
* Il report delle 08:30 non viene bloccato dai soli componenti interni Sentinel, ma ne esplicita l'esclusione dal feed esterno.
* Nessuna modifica necessaria ai Client o alle chiavi di comunicazione.

== 1.4.0 - 14 October 2026 ==
* Report di sicurezza HTML giornaliero alle ore 08:30 nel fuso orario di WordPress.
* Email responsive con riepilogo aziendale, indicatori colorati, distribuzione siti, dettaglio clienti e componenti prioritari.
* Attestazione di monitoraggio verde solo con inventari e feed recenti e completi, senza vulnerabilita note e senza parti escluse.
* Alert HTML su nuove vulnerabilita, raggruppati per sito e plugin con gravita, CVSS, intervalli di versione, fonti, patch e indicazioni di compatibilita.
* Coda temporizzata per evitare decine di mail durante scansioni progressive e deduplicazione degli alert gia inviati.
* Destinatari multipli (fino a 12), opzioni separate per rapporto quotidiano e allarmi.
* Anteprima report dal Master, invio email di prova, stato ultimo invio e prossimo orario pianificato.
* Avvisi sui siti offline trasformati in email HTML; segnalazioni WPScan chiaramente indicate come da verificare.
* Invio programmato tramite WP-Cron: per rispettare l'orario serve un cron reale del server e un SMTP configurato.
* Nessuna modifica richiesta al Client, alle REST API e alle chiavi dei siti.

== 1.3.0 - 14 October 2026 ==
* Dashboard aziendale: indicatori sintetici, distribuzione dei siti per stato e plugin piu esposti su piu installazioni.
* Schede dei siti con conteggi separati per componenti vulnerabili e advisory, copertura del feed e priorita.
* Grafici leggeri locali (CSS), senza librerie JS o servizi esterni.
* Dettagli raggruppati per plugin: gravita massima, versioni, stato API, dipendenze, advisory espandibili.
* Ricerca e filtri: tutti, vulnerabili, da aggiornare, da verificare, senza vulnerabilita note.
* WPScan, temi, storico e impostazioni disponibili in sezioni tecniche espandibili.
* Aggiunto fallback per la gravita dichiarata da API quando il valore numerico non e fornito.
* Nessuna modifica alla REST API Client, alle chiavi, alle impostazioni o ai dati memorizzati.

== 1.2.0 - 14 October 2026 ==
* Gestione del formato WPVulnerability data.vulnerability e dei suoi intervalli operator.
* Stato di sicurezza per ogni plugin: vulnerabile, nessuna vulnerabilita nota, in attesa, errore API, non censito o privato.
* Analisi immediata per sito tramite AJAX progressivo, indipendente dalla schedulazione WP-Cron.
* Riepilogo della copertura effettiva dell'analisi, inclusi errori e pending.
* Evidenza delle dipendenze Elementor/WooCommerce anche per componenti non aggiornabili.
* Corretto falso positivo su integrazioni WooCommerce opzionali dichiarate via header WC requires at least.
* Segnalazioni PHP 7.x fuori supporto e componenti Activator da verificare manualmente.
* Mantiene tutte le opzioni, le credenziali, il feed WPScan opzionale e lo storico della 1.1.0.

== Note ==
I risultati dipendono dalla copertura dei fornitori e dalla corretta identificazione dei plugin.
L'assenza di vulnerabilita note non garantisce la sicurezza del componente.
Nessun aggiornamento remoto automatico viene eseguito.
