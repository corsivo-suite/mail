# Corsivo Mail

Infrastruttura email della suite Corsivo: coda di invio durevole, account SMTP multipli con credenziali cifrate, template Gutenberg, log operativo e trasporto condiviso per gli altri plugin della famiglia.

## Scopo

WordPress spedisce email in modo sincrono, senza retry, senza storia e senza garanzie: se il trasporto fallisce durante una richiesta, il messaggio è perso. Corsivo Mail esiste per dare alla suite un unico punto di uscita affidabile: gli invii espliciti della suite e le chiamate `wp_mail` che soddisfano la policy di accodamento passano da una coda durevole con lease e retry, vengono inviate da un worker fuori dalla richiesta HTTP e lasciano una traccia consultabile. Allegati, embed, coda disattivata e bypass runtime restano sincroni. I plugin della suite (Bookings, Members, Newsletter) non parlano con SMTP: accodano qui e osservano l'esito.

Il plugin copre volutamente uno scope ampio: trasporto e coda, template email editabili a blocchi, automazioni, un modulo IMAP opzionale per leggere le caselle e un modulo newsletter interno per gli utenti registrati. I confini con Corsivo Newsletter sono descritti più sotto.

## Come funziona

### Moduli

- `includes/settings/` — account SMTP/IMAP, opzioni, cifratura (`crypto.php`), import/export, pagina admin
- `includes/smtp/` — configurazione PHPMailer per-account
- `includes/queue/` — schema e verifica dello storage, coda, worker, endpoint cron, journal
- `includes/storage/` — schema del log
- `includes/template/` — CPT dei template, compilazione Gutenberg email-safe, merge tag, hook su `wp_mail`
- `includes/automation/` — CPT automazioni, audience, invii manuali/bulk/programmati, eventi, email core
- `includes/newsletter/` — modulo consensi interno: preferenza utente, unsubscribe, gruppi, blocco
- `includes/imap/` — client TLS, sync, inbox e webmail admin
- `includes/integrations/` — Corsivo Theme, Layouts, WPML, WooCommerce
- `includes/api.php`, `includes/functions.php` — API pubbliche per gli altri plugin

### Entità dati

Tre tabelle InnoDB, tutte nel namespace `{prefix}corsivo_mail_`:

- `corsivo_mail_queue` — la coda. Colonne chiave: `status` (ciclo di vita `pending`, `sending`, `transmitting`, `sent`, `failed`, `cancelled`, `delivery_unknown`), `locked_at`/`lock_token` (lease), `attempts`/`max_attempts`, `scheduled_at`, `idempotency_key` (UNIQUE, hashata) con `idempotency_fingerprint`, `source_type`/`source_id`, `source_guard_required`, `retain_until`, `message_type` (`transactional`/`marketing`), payload leggibili (`body` come testo; `headers`, `attachments`, `embeds` come JSON), `transport_started_at`, `error_code`.
- `corsivo_mail_log` — journal append-only: `event`, `queue_id`, `attempt`, sorgente, `account_id`, `actor_user_id`, `recipient` più `recipient_hash`, `subject`, `status`, errore redatto, `metadata` filtrata su chiavi ammesse.
- `corsivo_mail_messages` — indice dei metadati IMAP (mittente, oggetto, data, uid, seen); i corpi non vengono persistiti. Installata solo con il modulo IMAP attivo.

Account SMTP/IMAP in un'option dedicata con password cifrate; impostazioni in `corsivo_mail_settings`.

### Ciclo di vita di un invio

1. **Accodamento.** `wp_mail` viene intercettato su `pre_wp_mail`: se la coda è attiva il messaggio diventa una riga `pending` con payload plain leggibili e la chiamata ritorna `true`; short-circuit già impostati da altri plugin vengono rispettati. Messaggi con allegati o embed passano sincroni. Il filtro `wp_mail` applica il wrapping nel layout HTML (disattivabile). I plugin della suite accodano invece via API con chiave idempotente.
2. **Claim.** Il worker (cron ogni minuto) reclama le righe scadenzate con un singolo `UPDATE` atomico: `pending` diventa `sending`, `lock_token` UUID, `attempts` incrementato. Nessun'altra istanza può prendere la stessa riga.
3. **Guardie.** Prima del trasporto: fence della sorgente (`corsivo_mail_queue_source_guard_{source_type}` deve autorizzare le righe con guard obbligatoria), consenso marketing rivalutato al momento dell'invio per `message_type = marketing` (un solo destinatario, altrimenti soppressione).
4. **Trasporto.** La riga passa a `transmitting` (`transport_started_at` valorizzato, fence su `cancel_requested`), poi `wp_mail` parte con l'account SMTP della riga via PHPMailer.
5. **Esito.** Solo un esito confermato dal trasporto chiude la riga come `sent` (payload azzerati). Un fallimento segnalato viene ritentato con backoff esponenziale e jitter fino a `max_attempts`, poi `failed`. Un esito non verificabile dopo l'avvio del trasporto — eccezione, worker morto a metà — termina come `delivery_unknown`: mai retry cieco di un invio che potrebbe essere partito. `sent` significa "il trasporto ha accettato", non "la casella ha consegnato".
6. **Terminale.** Ogni chiusura (`sent`, `failed`, `cancelled`, `delivery_unknown`) emette `corsivo_mail_queue_terminal` con l'identità della sorgente, così Bookings e Members riconciliano il proprio stato.

Il recupero delle righe stantie gira a ogni ciclo: `sending` scadute tornano `pending` (o `failed` con `worker_died` a tentativi esauriti), `transmitting` scadute diventano `delivery_unknown`, le richieste di cancellazione della sorgente vengono onorate.

### Storage e installazione

Lo schema è verificato fisicamente: engine InnoDB, colonne, indici e inventario del namespace devono corrispondere esattamente al codice corrente. L'installazione è fresh-only a due fasi (intent, creazione verificata, marker plain finale) sotto lock di database e accetta soltanto il formato corrente. Uno schema completo e fisicamente esatto viene riattestato anche se già popolato; uno schema incoerente o dati inattesi bloccano il runtime fail-closed con errore persistito e notice admin, senza toccare i dati esistenti.

La coda ha anche verifiche di integrità di dominio: payload attivi leggibili e strutturalmente validi, ciclo di vita coerente, policy delle sorgenti garantite rispettata.

### Cron

- `corsivo_mail_process_queue` — ogni minuto: automazioni programmate (priorità 5) e poi la coda.
- `corsivo_mail_cleanup_queue` — giornaliero: retention di coda e log; le sorgenti possono alzare la propria via `corsivo_mail_queue_source_retention_days`, `retain_until` è sempre rispettato.
- Endpoint esterno `/?corsivo_mail_cron={chiave}` (o header `X-Corsivo-Mail-Key`, o `Authorization: Bearer`): esegue schedule e coda, risponde 503 a storage non disponibile. Chiave rigenerabile dalle impostazioni.

## Punti rilevanti

- **Coda durevole con lease.** Claim atomico, lock token per ogni transizione, requeue delle righe abbandonate, backoff esponenziale con jitter. Nessuna email viene processata da due worker contemporaneamente, nessuna resta bloccata per sempre.
- **Idempotenza.** La chiave idempotente (hashata, UNIQUE) è accompagnata da una fingerprint canonica del payload: il replay della stessa richiesta restituisce la riga esistente, una chiave riusata con payload diverso è un errore esplicito. Il vincolo è del database, non del codice chiamante.
- **Stati onesti.** `delivery_unknown` esiste perché "non so se è partita" è diverso sia da `sent` sia da `failed`: dopo l'avvio del trasporto un esito non confermato non viene mai ritentato alla cieca né spacciato per consegnato.
- **Cifratura con chiave propria.** AES-256-GCM in envelope `cmenc:v3` (versione, algoritmo, `key_id`, nonce, tag, ciphertext). La master key è letta da `CORSIVO_MAIL_ENCRYPTION_KEY` oppure generata una sola volta e persistita in `corsivo_mail_encryption_master_key` (non autoload, `INSERT IGNORE` per essere atomica tra worker concorrenti); da lì `hash_hkdf` deriva la chiave di payload. **Non dipende dai salt WordPress:** ruotarli non rende illeggibile nulla. L'AAD lega plugin, blog, versione, `key_id` e tipo di credenziale, quindi una password SMTP non può essere spostata nel campo IMAP. Cifra esclusivamente le credenziali SMTP/IMAP; i payload della coda restano testo e JSON leggibili. I payload vengono azzerati alla chiusura della riga (`sent`, `cancelled`, `delivery_unknown`); restano leggibili sulle righe `failed` per permettere il retry manuale, fino alla retention.
- **Log senza segreti.** Il log conserva destinatario e oggetto ma mai body, header, allegati o credenziali; gli errori sono redatti (password/token e query string rimossi dalle URL). Accanto al destinatario c'è `recipient_hash`, SHA-256 stabile dell'indirizzo normalizzato: non dipende da HMAC o salt WordPress e serve per correlare le righe e per gli strumenti privacy di WordPress (exporter ed eraser coprono log e coda).
- **Fail-closed.** Senza storage verificabile il runtime non si carica: quando Corsivo Mail governa il trasporto, gli invii vengono bloccati invece di uscire non tracciati; worker ed endpoint cron rispondono unavailable. Il kill switch `disable_sending` sopprime ogni invio con traccia a log.
- **Sorgenti garantite.** Le email di Bookings e Members (`corsivo-bookings-payment-request`, `corsivo-bookings-stay-reminder`, `corsivo-members-expiry`, `corsivo-members-renewal`) esigono guard attiva e `retain_until` di almeno 8 giorni; l'accodamento senza policy corretta viene rifiutato. Le sorgenti dispongono di fence durevole (`cancel_requested`) e cancellazione verificata.
- **Confini con Corsivo Newsletter.** Sovrapposizione dichiarata: Mail include un modulo newsletter interno (consenso in user_meta per utenti registrati, blocco iscrizione, unsubscribe HMAC con one-click) che copre solo il caso "utenti del sito". Corsivo Newsletter possiede l'audience esterna con double opt-in, i provider e le campagne. Quando Newsletter è attivo il modulo interno parte disabilitato per mantenere una sola fonte di verità e il consenso marketing viene delegato allo stato `confirmed` dell'iscritto esterno, riverificato dal worker prima di ogni trasporto.
- **IMAP difensivo.** TLS con verifica del peer, limite sulla dimensione delle risposte, HTML remoto sanitizzato e servito con CSP sandbox in documenti isolati. La webmail è solo per amministratori.
- **Superficie amministrativa chiusa.** CPT di template e automazioni con capability `manage_options`, route REST amministrative autenticate.

## Integrazioni con la suite

API per le sorgenti (Bookings, Members, chiunque registri email):

```php
corsivo_mail_enqueue_registered($provider_id, $to, $context, $idempotency_key, $source);
corsivo_mail_fence_queued($queue_id, $source_type, $source_id);
corsivo_mail_cancel_queued($queue_id, $source_type, $source_id, $error_code);
corsivo_mail_queue_status($queue_id, $source_type, $source_id);

corsivo_mail_deliver($core_email_id, $to, $context, $headers);
corsivo_mail_core_email_status($core_email_id);
corsivo_mail_send($post_id, $to, $context);
```

- **Bookings / Members → coda.** Accodano email registrate con idempotenza e policy di retention; l'action `corsivo_mail_queue_terminal` notifica l'esito, il filtro `corsivo_mail_queue_source_guard_{source_type}` è il veto della sorgente al momento dell'invio.
- **Email core registrabili.** Il filtro `corsivo_mail_core_emails` registra email con template a blocchi editabile: il core WordPress (reset password, nuovo utente) è già coperto; Corsivo Newsletter vi registra `corsivo_newsletter_confirm` e la consegna con `corsivo_mail_deliver`.
- **Newsletter → audience.** Corsivo Newsletter pubblica gli iscritti confermati come audience delle automazioni via `corsivo_mail_audiences`, `corsivo_mail_resolve_recipients`, `corsivo_mail_recipient_count`, con merge tag `unsubscribe_url`.
- **Estensione.** Eventi (`corsivo_mail_events`), merge tag (`corsivo_mail_merge_tags`; `{{meta:chiave}}` solo per chiavi ammesse via `corsivo_mail_user_meta_keys`), rendering (`corsivo_mail_base_styles`, `corsivo_mail_color_palette`, `corsivo_mail_font_sizes`, `corsivo_mail_spacing_scale`, `corsivo_mail_document_head`, `corsivo_mail_starter_templates`), trasporto (`corsivo_mail_smtp_presets`, `corsivo_mail_smtp_timeout`, `corsivo_mail_imap_max_response_bytes`, `corsivo_mail_editor_recipient_limit`).
- **Integrazioni opzionali.** WooCommerce (le email native restano a WooCommerce: né wrapping né coda), WPML (risoluzione di automazioni e layout nella lingua del destinatario), Corsivo Theme (palette e font ereditati quando abilitato e rilevato), Corsivo Layouts (starter template via filtro).

## Test

Con WordPress caricato (WP-CLI):

```sh
wp eval-file wp-content/plugins/corsivo-mail/tests/database-integrity.php
wp eval-file wp-content/plugins/corsivo-mail/tests/queue-storage.php
wp eval-file wp-content/plugins/corsivo-mail/tests/security-queue.php
wp eval-file wp-content/plugins/corsivo-mail/tests/transport-contract.php
wp eval-file wp-content/plugins/corsivo-mail/tests/footer-layout.php
```

- `database-integrity` — engine InnoDB e colonne reali delle tre tabelle.
- `queue-storage` — macchina a stati dello storage: installazione, recovery, condizioni di blocco.
- `security-queue` — autorizzazioni REST e CPT, payload coda plain, roundtrip e tamper delle credenziali cifrate, sanitizzazione HTML IMAP, soppressione marketing multi-destinatario e consenso esterno.

Esistono anche `admin-render.php` (rendering di tutti i tab admin) e `transfer-roundtrip.php` (import/export), sempre via `wp eval-file`.

Standalone, senza WordPress:

```sh
php wp-content/plugins/corsivo-mail/tests/crypto-early-boot.php
php wp-content/plugins/corsivo-mail/tests/storage-plain-early-boot.php
php wp-content/plugins/corsivo-mail/tests/schema-current-static.php
```

I primi due dimostrano che cifratura delle credenziali e verifica dello stato storage funzionano early-boot (fail-closed quando non c'è né costante né database, nessun caricamento di `pluggable.php`); il terzo è un controllo statico sul sorgente che impedisce la reintroduzione di installer lazy, del versioning di schema e di qualunque ritorno dei salt WordPress dentro `crypto.php`.

## Configurazione

- **Account.** SMTP multipli con preset (Gmail, Aruba, OVH, Microsoft 365, Brevo, SendGrid, ecc.), mittente e account predefinito; credenziali cifrate al salvataggio, mai riproposte in chiaro. IMAP opzionale per account.
- **Coda.** `use_queue`, batch size (default 20), retry massimi (3), pausa tra invii, minuti di lock (10), retention coda (30 giorni) e log (90).
- **Trasporto.** `wrap_wp_mail` per il wrapping del layout, `disable_sending` come kill switch globale.
- **Moduli.** IMAP e preferenze newsletter interne attivabili dal tab Moduli, insieme alle integrazioni Corsivo Theme, WPML e WooCommerce; chiavi meta utente ammesse come merge tag.
- **Cron esterno.** URL con chiave dedicata, rigenerabile dalle impostazioni, per siti senza traffico regolare.
- **Chiave di cifratura.** Facoltativa: senza di essa la master key viene generata al primo uso e salvata a database.

```php
define('CORSIVO_MAIL_ENCRYPTION_KEY', 'inserisci-almeno-32-byte-casuali');
```

  Definirla tiene la chiave fuori dal database, così un dump non basta a leggere le credenziali. Una volta scelta va mantenuta stabile e inclusa nei backup: cambiarla o rimuoverla rende illeggibili le credenziali salvate, che vanno reinserite. Ruotare i salt WordPress invece non ha alcun effetto.
- **Requisiti.** WordPress 6.6+, PHP 8.1+, OpenSSL con AES-256-GCM.

L'editor Gutenberg, la Posta in arrivo e i tab React Account/Gruppi vengono compilati rispettivamente da `admin-editor`, `inbox-app` e `settings-app`, incorporando il package privato `@corsivo-suite/wp-admin-ui`. In ciascuna directory `npm install` seguito da `npm run check` rigenera gli asset, verifica i controlli e valida il wiring PHP. Se una build è incompleta, WordPress carica lo script classico e il relativo CSS in `assets` senza interrompere l'interfaccia.
## Bootstrap, entry point e lifecycle

`corsivo-mail.php` carica per primi soltanto i componenti necessari a leggere configurazione e stato dello storage: cifratura/account/opzioni, schema della coda e del log/IMAP, primitive della coda, worker ed endpoint cron. Questa parte early-boot può quindi verificare lo storage e, se Corsivo Mail governa il trasporto globale, bloccare `wp_mail` senza dipendere dal runtime applicativo. Il filtro conserva qualunque short-circuit già deciso da un componente precedente.

`load_runtime()` include il resto soltanto dopo `required_storage_ready()`: sicurezza e admin, SMTP e journal, template e blocchi, automazioni, newsletter interna, IMAP, integrazioni e API pubblica. L'ordine evita che CPT, route o worker diventino parzialmente disponibili quando le tabelle richieste non sono verificabili.

L'attivazione installa lo storage fresh-only, registra CPT e cron e verifica subito il risultato; la disattivazione elimina le pianificazioni e i flag di verifica, senza cancellare coda, log, template o account. `uninstall.php` è il solo percorso che elimina tabelle, opzioni, meta, cron e contenuti di servizio del plugin. Non esiste un installer lazy nel traffico ordinario.

Gli entry point sono: intercettazione `pre_wp_mail`; funzioni PHP pubbliche in `includes/api.php`; route REST amministrative per configurazione, template, automazioni e inbox; hook cron interno ed endpoint cron autenticato; schermate WordPress; blocco newsletter e componenti dell'editor; integrazione PHPMailer e, quando abilitato, sincronizzazione IMAP.

## Mappa completa dei file

| File | Responsabilità |
|---|---|
| `.gitignore` | Esclusioni locali e artefatti non distribuibili. |
| `.distignore` | Allowlist negativa del pacchetto distribuibile, inclusa l'esclusione dei sorgenti e delle dipendenze di build. |
| `corsivo-mail.php` | Bootstrap early/runtime, readiness, attivazione/disattivazione e protezione fail-closed di `wp_mail`. |
| `includes/api.php` | API pubblica durevole per enqueue, fence, cancel, status e consegna di email registrate. |
| `includes/functions.php` | Helper applicativi condivisi per template, invio e risoluzione dei contenuti. |
| `includes/security.php` | Capability, nonce, sanitizzazione, header e policy di sicurezza delle superfici admin/REST. |
| `includes/settings/crypto.php` | Envelope AES-GCM stabile per le sole credenziali SMTP/IMAP. |
| `includes/settings/accounts.php` | Modello, validazione e persistenza degli account di trasporto e ricezione. |
| `includes/settings/options.php` | Schema, default, normalizzazione e lettura delle opzioni del plugin. |
| `includes/settings/page.php` | Pagina impostazioni, tab, diagnostica, salvataggi protetti e asset admin. |
| `includes/settings/transfer.php` | Export/import controllato della configurazione e roundtrip dei dati trasferibili. |
| `includes/smtp/phpmailer.php` | Selezione account e configurazione di PHPMailer per il trasporto SMTP. |
| `includes/queue/schema.php` | Blueprint, installazione fresh-only e verifica fisica della coda. |
| `includes/queue/queue.php` | Inserimento idempotente, lettura, stati, fence, cancellazione e retention delle righe. |
| `includes/queue/worker.php` | Claim con lease, guardie sorgente/marketing, trasporto, retry e stati terminali. |
| `includes/queue/cron-endpoint.php` | Pianificazione, endpoint autenticato e coordinamento dell'esecuzione esterna. |
| `includes/queue/log.php` | Scrittura degli eventi operativi della coda nel journal. |
| `includes/storage/contract.php` | Contratto comune per schema, marker plain, verifica e readiness degli storage. |
| `includes/storage/log.php` | Blueprint, installazione e accesso alla tabella append-only del log. |
| `includes/template/cpt.php` | Registrazione e autorizzazioni del CPT dei template email. |
| `includes/template/merge-tags.php` | Catalogo, parsing e risoluzione sicura dei merge tag. |
| `includes/template/compiler.php` | Compilazione del contenuto Gutenberg in markup email-safe. |
| `includes/template/render.php` | Composizione documento, stili, layout e rendering finale. |
| `includes/template/wp-mail-hook.php` | Wrapping e accodamento delle chiamate WordPress a `wp_mail`. |
| `includes/blocks/content-slot.php` | Blocco dinamico per inserire contenuti/slot nei template. |
| `includes/blocks/editor.php` | Registrazione blocchi, restrizioni e configurazione dell'editor email. |
| `includes/automation/cpt.php` | CPT, meta e capability delle automazioni. |
| `includes/automation/payload.php` | Canonicalizzazione e validazione del payload di un'automazione. |
| `includes/automation/core-emails.php` | Registro delle email core e loro associazione ai template editabili. |
| `includes/automation/audience.php` | Contratto delle audience, conteggio e risoluzione dei destinatari. |
| `includes/automation/send.php` | Preparazione e accodamento degli invii di automazione. |
| `includes/automation/bulk.php` | Azioni massive e batch amministrativi sugli invii. |
| `includes/automation/admin-columns.php` | Colonne, stato e azioni rapide nella lista delle automazioni. |
| `includes/automation/events.php` | Registro degli eventi che possono attivare automazioni. |
| `includes/automation/meta-rest.php` | Esposizione e validazione REST dei metadati dell'automazione. |
| `includes/automation/schedule.php` | Calcolo delle occorrenze e pianificazione degli invii programmati. |
| `includes/automation/runtime.php` | Dispatch degli eventi e coordinamento runtime delle automazioni attive. |
| `includes/automation/preview.php` | Anteprima protetta con destinatario e contesto controllati. |
| `includes/automation/seed.php` | Creazione esplicita dei template/automazioni iniziali previsti dal plugin. |
| `includes/newsletter/subscription.php` | Preferenza marketing per utenti registrati e controllo del consenso. |
| `includes/newsletter/unsubscribe.php` | URL one-click, verifica del token e revoca del consenso. |
| `includes/newsletter/groups.php` | Modello e assegnazione dei gruppi newsletter interni. |
| `includes/newsletter/groups-page.php` | UI amministrativa dei gruppi. |
| `includes/newsletter/block.php` | Registrazione e callback del blocco di iscrizione. |
| `includes/imap/schema.php` | Blueprint e verifica della tabella indice dei messaggi IMAP. |
| `includes/imap/client.php` | Client IMAP TLS, parsing delle risposte e limiti di trasporto. |
| `includes/imap/sync.php` | Sincronizzazione UID/metadati e aggiornamento dello stato locale. |
| `includes/imap/inbox.php` | Query e azioni dell'inbox amministrativa. |
| `includes/imap/page.php` | Registrazione e rendering della webmail in admin. |
| `includes/imap/test.php` | Diagnostica protetta della connessione IMAP. |
| `includes/integrations/layout-selector.php` | Collegamento ai layout/starter template disponibili nella suite. |
| `includes/integrations/corsivo-theme.php` | Import di palette, font e token da Corsivo Theme quando abilitato. |
| `includes/integrations/wpml.php` | Risoluzione lingua e traduzioni con WPML. |
| `includes/integrations/woocommerce.php` | Esclusione e coordinamento delle email possedute da WooCommerce. |
| `assets/editor.css` | Stili dell'editor dei template e delle automazioni. |
| `assets/editor.js` | Comportamento comune dell'editor Gutenberg email. |
| `assets/email-editor.js` | Controlli specifici del documento/template email. |
| `admin-editor/package.json` | Pipeline wp-scripts e dipendenza privata da Corsivo Admin UI. |
| `admin-editor/src/index.js` | Entry compilata che monta i controlli condivisi e registra l'editor esistente. |
| `admin-editor/build/` | Bundle JavaScript e CSS LTR/RTL distribuiti con il plugin. |
| `inbox-app/` | Pipeline e bundle LTR/RTL della webmail con dipendenza privata da Corsivo Admin UI. |
| `assets/inbox.js` | Interazioni della webmail, dialog accessibile, protezioni dalle race e fallback classico. |
| `assets/inbox.css` | Layout scoped della Posta in arrivo e fallback RTL tramite proprietà logiche. |
| `settings-app/` | Pipeline e bundle LTR/RTL dei tab Account e Gruppi con Repeater, ConfirmDialog e FieldFeedback. |
| `assets/settings.js` | Progressive enhancement classico dei tab Account e Gruppi quando la build non è disponibile. |
| `assets/settings.css` | Layout scoped condiviso dalle isole React e dai fallback server-rendered. |
| `assets/newsletter-editor.js` | UI editor del blocco newsletter. |
| `assets/newsletter.js` | Submit e feedback frontend del blocco di iscrizione. |
| `tests/admin-render.php` | Smoke del rendering delle schermate amministrative. |
| `tests/editor-assets.php` | Gate statico del bundle editor e assenza di import privati a runtime. |
| `tests/editor-enqueue-smoke.php` | Smoke del wiring build/fallback, dipendenze, configurazione e RTL. |
| `tests/inbox-assets.php` | Gate statico del bundle e degli stili della Posta in arrivo. |
| `tests/inbox-enqueue-smoke.php` | Smoke del wiring compilato della Posta in arrivo. |
| `tests/inbox-fallback-smoke.php` | Smoke del fallback classico quando la build della Posta in arrivo è incompleta. |
| `tests/inbox-contract.php` | Contratto statico di route, race guard, conferma asincrona e sandbox degli allegati. |
| `tests/settings-ui-assets.php` | Gate statico del bundle e degli stili dei tab Account e Gruppi. |
| `tests/settings-ui-enqueue-smoke.php` | Smoke del wiring compilato, configurazione localizzata e RTL delle impostazioni. |
| `tests/settings-ui-fallback-smoke.php` | Smoke del fallback server-rendered quando la build delle impostazioni è incompleta. |
| `tests/settings-ui-post-contract.php` | Roundtrip POST di Account e Gruppi, credenziali vuote e guardia rimozione account. |
| `tests/crypto-early-boot.php` | Contratto early-boot della cifratura e indipendenza dai salt WordPress. |
| `tests/database-integrity.php` | Verifica dello schema reale in un WordPress caricato. |
| `tests/footer-layout.php` | Verifica che il footer dei wrapper segua l'impostazione flottante o a tutta larghezza anche negli invii intercettati da `wp_mail`. |
| `tests/queue-storage.php` | Macchina a stati, installazione, recovery e blocchi dello storage coda. |
| `tests/schema-current-static.php` | Guard statiche sul contratto current-only e sull'indipendenza dai salt. |
| `tests/security-queue.php` | Autorizzazioni, payload, consenso, sanitizzazione e guardie della coda. |
| `tests/storage-plain-early-boot.php` | Readiness del marker plain e comportamento fail-closed early-boot. |
| `tests/transfer-roundtrip.php` | Roundtrip di export/import delle impostazioni. |
| `tests/transport-contract.php` | Contratto worker/PHPMailer e classificazione verificata degli esiti. |
| `uninstall.php` | Rimozione multisite-safe di tabelle, opzioni, cron e contenuti posseduti. |
| `changelog.txt` | Storico delle release e delle modifiche contrattuali. |
| `corsivo-mail-0.23.1.zip` | Artefatto di release della versione indicata, non caricato dal runtime. |
| `LICENSE` | Testo della licenza GPL-2.0-or-later. |
| `README.md` | Specifica operativa e mappa del plugin. |
