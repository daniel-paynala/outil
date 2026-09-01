# Les erreurs de Paynala — une requête indépendante par type

Dix-neuf fichiers dans [`sondes/`](sondes/). **Chacun est autonome** : une
requête complète, sans vue à créer, sans socle partagé, sans dépendance aux
autres. Tu en colles une dans le champ « Requête » d'une sonde Arche, elle
marche seule.

Quinze d'entre elles interrogent **une seule table**. La plus longue fait
quatorze lignes.

---

## Ce qui a permis de les raccourcir

Les premières versions partaient de `payment` et rejoignaient `airtel_logs`
trois fois, comme le fait le dashboard. C'était soixante lignes par sonde,
recopiées quinze fois.

Le dashboard a une bonne raison de faire ça : il **affiche** les trois jambes
côte à côte sur une ligne de tableau, donc il lui faut le paiement et ses trois
logs ensemble. Une sonde ne fait que compter. Elle n'a besoin ni du paiement,
ni des trois jambes en même temps.

Or les logs de réconciliation portent leur jambe dans leur identifiant :

| Jambe | Fin de `request_id` |
|---|---|
| CP | `CP` |
| MC — tout le monde sauf Rengus | `MC` |
| MC1 — Rengus Digital | `MC1` |
| MC2 — Rengus Digital | `MC2` |

`request_id like '%CP'` suffit donc à isoler la première jambe. Aucune
jointure. C'est ce qui fait passer une sonde de soixante lignes à cinq.

---

## Les dix-huit

### Une jambe a répondu non — une table, cinq lignes

| # | Fichier | Jambe |
|---|---|---|
| 01 | `01-cp-echec.sql` | CP |
| 02 | `02-mc1-echec.sql` | MC1 — Rengus Digital |
| 02b | `02b-mc-echec.sql` | MC — tous les autres marchands |
| 03 | `03-mc2-echec.sql` | MC2 — Rengus Digital |

MC1 et MC sont **deux fichiers**, pas un : ce sont deux populations de
marchands différentes, et les mélanger empêcherait de voir que Rengus va mal
pendant que les autres vont bien.

```sql
-- CP a répondu non. Une seule table, aucune jointure.
select count(*) as valeur
from airtel_logs
where created_at >= :depuis
  and request_id like '%CP'
  and response->'status'->>'success' is distinct from 'true'
```

### Une jambe n'a laissé aucun log

| # | Fichier | Jambe |
|---|---|---|
| 04 | `04-cp-sans-log.sql` | CP |
| 05 | `05-mc1-sans-log.sql` | MC1 — Rengus Digital |
| 05b | `05b-mc-sans-log.sql` | MC — tous les autres |
| 06 | `06-mc2-sans-log.sql` | MC2 — Rengus Digital |

**Les quatre seules qui touchent deux tables**, et c'est irréductible : une
ligne absente ne se compte pas dans la table où elle manque. Il faut partir des
paiements et chercher ce qui n'y répond pas.

C'est aussi pourquoi elles gardent les filtres du dashboard — `status =
'SUCCESS'` et les quatre canaux réconciliables : sans eux, elles compteraient
comme manquants les logs de paiements qui n'en attendaient aucun.

```sql
-- CP n'a laissé aucun log. Le seul cas qui exige la table payment : une
-- ligne absente ne se compte pas dans la table où elle manque.
select count(*) as valeur
from payment p
where p.created_at >= :depuis
  and p.status = 'SUCCESS'
  and p.channel in ('WEB', 'USSD', 'API', 'RECOVERY')
  and not exists (
      select 1 from airtel_logs al
      where al.request_id = case
          when p.channel = 'USSD' then p.airtel_money_id || 'CP'
          else p.request_id || '_CP'
      end
  )
```

### Un type d'erreur — une table, six à dix lignes

| # | Fichier | Ce qu'elle compte |
|---|---|---|
| 07 | `07-timeout.sql` | Time-out, quelle que soit la forme du log |
| 08 | `08-invalid-transaction-id.sql` | Identifiant de transaction refusé |
| 09 | `09-solde-insuffisant.sql` | Solde insuffisant |
| 10 | `10-coupure-reseau.sql` | Connexion coupée : socket, DNS, refus |
| 11 | `11-infrastructure-non-classee.sql` | **Filet** — panne technique inconnue |
| 12 | `12-echec-non-detaille.sql` | Airtel refuse sans dire pourquoi |
| 13 | `13-refus-metier-non-classe.sql` | **Filet** — refus métier inconnu |

```sql
-- Time-out, quelle que soit la forme du log.
select count(*) as valeur
from airtel_logs
where created_at >= :depuis
  and request_id ~ '(CP|MC|MC1|MC2)$'
  and response->'status'->>'success' is distinct from 'true'
  and coalesce(response->>'message', response->'status'->>'message') ~* 'timeout|time out|timed out|ETIMEDOUT|ESOCKETTIMEDOUT'
```

### Hors réconciliation

| # | Fichier | Ce qu'elle compte |
|---|---|---|
| 14 | `14-paiements-en-echec.sql` | Paiements en statut `FAILED` |
| 15 | `15-silence-production.sql` | Minutes depuis le dernier paiement |
| 17 | `17-paiements-reussis.sql` | Paiements en statut `SUCCESS` |
| 16 | `16-decouverte-des-libelles.sql` | *Pas une sonde* — voir plus bas |

### La sonde 17 ne mesure pas une panne, et ça change tout

```sql
select count(*) as valeur
from payment
where created_at >= :depuis
  and status = 'SUCCESS'
```

*Unité : `paiements réussis` · 24 h : `100`*

C'est la seule sonde qui compte une bonne nouvelle. Le mécanisme des paliers,
lui, a été conçu pour des incidents — et la différence se paie.

**Elle ne notifiera qu'une seule fois.** Vérifié en rejouant la vraie classe
`Tiers` d'Arche sur quatre jours :

```
Jour 1 — 22h    valeur  104  →  NOTIFICATION (palier 100)
Jour 2 — 22h    valeur  112  →  silence
Jour 3 — 22h    valeur   98  →  silence
Jour 4 — 22h    valeur  130  →  silence
```

Ce n'est pas un défaut, c'est la règle qui rend les fenêtres glissantes
utilisables : on ne signale qu'un palier **strictement supérieur** au plus haut
déjà signalé, sinon 99 → 100 → 99 → 100 produirait des dizaines d'alertes pour
un seul incident. Et le plus haut palier signalé ne redescend que sur
acquittement explicite — la décision que tu as prise en écartant la remise à
zéro automatique.

Ajouter des paliers plus hauts (`100, 200, 300`) ne change rien : 112 n'atteint
pas 200.

**Ce qui fonctionne aujourd'hui :** acquitter chaque matin. Le comptage repart,
et le franchissement du lendemain se signale à nouveau.

```
Jour 1 — 22h    valeur  104  →  NOTIFICATION (palier 100)   puis acquitté
Jour 2 — 22h    valeur  112  →  NOTIFICATION (palier 100)   puis acquitté
Jour 3 — 22h    valeur   98  →  silence
Jour 4 — 22h    valeur  130  →  NOTIFICATION (palier 100)
```

Le geste est le bon, le mot ne l'est pas : le bouton dit « C'est traité », ce
qui se lit mal pour cent paiements réussis.

**Ce qui manque pour que ce soit propre :** un réarmement automatique quand la
valeur repasse sous le plus bas palier. Deux lignes dans `ProbeRunner`, une
colonne booléenne sur la sonde, et ce type de seuil récurrent se gérerait seul —
sans toucher au modèle d'acquittement des incidents, qui doit rester manuel.
C'est une décision à prendre, pas un oubli : dis-le-moi et je l'ajoute.

---

## La partition est stricte, et c'est vérifié

Les sept sondes de type forment une partition : **tout échec tombe dans
exactement une**. Ni deux, ni zéro. La logique des sept clauses a été rejouée
sur quinze messages couvrant chaque forme documentée plus des inconnus.

La ligne de partage principale est `jsonb_exists(response, 'error')` : les
logs d'infrastructure portent cette clé, les refus métier non. Seule la 07 la
traverse — un time-out peut se présenter sous les deux formes.

Contrôle en production :

```
01 + 02 + 02b + 03  =  07 + 08 + 09 + 10 + 11 + 12 + 13
```

Les deux côtés comptent les mêmes logs en échec, découpés autrement. S'ils
divergent, une sonde a été modifiée sans que la partition suive.

### Les deux filets

Les sondes **11** et **13** attrapent ce qu'aucune autre ne reconnaît. Elles ne
sont pas des fourre-tout : ce sont les deux seules qui peuvent te prévenir d'un
type d'erreur que personne n'avait anticipé.

**Si 13 monte pendant que 08 reste à zéro, c'est mon motif qui est faux, pas
Airtel qui va bien.**

---

## D'abord : la reconnaissance

`16-decouverte-des-libelles.sql` **ne se colle pas dans Arche** — elle rend des
lignes, pas un nombre. Elle se lance dans DBeaver ou l'éditeur SQL de Supabase,
sur trente jours, et liste chaque message d'erreur distinct avec sa forme et son
nombre d'occurrences.

Lance-la avant tout le reste. Les sondes 07, 08, 09 et 10 reposent sur des
motifs de texte que **le dépôt de référence ne contient pas** : `filters.js` ne
documente que deux exemples, `"Proxy request failed / socket hang up"` et
`"Solde insuffisant…"`. Le reste vient des erreurs usuelles de Node et des
libellés que tu as cités.

Un motif faux ne casse rien de visible : la sonde s'installe et ne signale
jamais rien. C'est le pire défaut possible — c'est précisément à elle qu'on fait
confiance.

| Sonde | Expression régulière | Statut |
|---|---|---|
| 07 | `timeout\|time out\|timed out\|ETIMEDOUT\|ESOCKETTIMEDOUT` | à confirmer |
| 08 | `invalid.*transaction\|transaction.*invalide` | à confirmer |
| 09 | `solde.*insuffisant\|insufficient\|balance` | à confirmer |
| 10 | `socket hang up\|ECONNRESET\|ECONNREFUSED\|ENETUNREACH\|EAI_AGAIN\|EHOSTUNREACH` | à confirmer |

Corriger un motif, c'est éditer une chaîne dans un fichier de six lignes.

---

## Deux écarts avec le dashboard, à connaître

Les sondes autonomes ne sont pas la requête du dashboard découpée : elles
partent d'ailleurs. Deux conséquences, toutes deux assumées.

**La fenêtre porte sur la date du log, pas sur celle du paiement.** Le dashboard
filtre `payment.created_at` parce qu'il répond à « qu'est-ce qui s'est passé sur
les transactions de cette période ». Une sonde répond à « qu'est-ce qui a raté
ces dernières heures » — la date de l'erreur est la bonne. Un log de
réconciliation arrivant le lendemain du paiement est compté le jour où il rate,
ce qui est ce qu'on veut d'une alerte.

**Le filtre `payment.status = 'SUCCESS'` disparaît des sondes 01-03 et 07-13.**
Une jambe de réconciliation n'est émise qu'après un paiement réussi, donc le
filtre devrait être sans effet. *Devrait* : je ne peux pas le vérifier sans la
base. Le test tient en une minute — lance **Essayer** sur la sonde 01 pour 24 h,
et compare au CP `FAILED` du dashboard sur la même journée. S'ils collent,
l'hypothèse tient.

Les sondes 04-06, elles, gardent les deux filtres : elles partent des paiements,
et là ils sont indispensables.

---

## Les règles reprises du projet

### 1. Trois états, jamais deux

`SUCCESS`, `FAILED`, `MISSING`. **Un log absent n'est pas un échec.**
`failedBeneficiaries.js` l'écrit noir sur blanc (« never "log absent", only a log
that came back unsuccessful ») et `walletFailures.js` ne compte que `= 'FAILED'`.

Un `FAILED` dit qu'Airtel a répondu non. Un `MISSING` dit que la réconciliation
n'a jamais été journalisée : un maillon s'est tu. Deux jeux de sondes séparés —
01-03 contre 04-06.

### 2. L'autorité est le drapeau du log, pas le code HTTP

`response->'status'->>'success' = 'true'`. `http_code` est fréquemment `NULL`
**même sur des succès authentiques**, sur les canaux API et RECOVERY. Une sonde
qui compterait `http_code >= 400` raterait des échecs et en inventerait
d'autres.

D'où le `is distinct from 'true'` de toutes les sondes d'échec : il traite
`NULL` comme un non-succès, ce que `!= 'true'` ne ferait pas.

### 3. Quatre canaux seulement se réconcilient

`WEB`, `USSD`, `API`, `RECOVERY`. `webview` et `backend_cron` ne portent jamais
de jambe CP/MC — ce n'est pas un défaut. Le filtre n'apparaît que dans les
sondes 04-06 : ailleurs, l'existence même du log l'implique.

### 4. Le suffixe dépend du canal

| | CP | deuxième | troisième |
|---|---|---|---|
| `USSD` | `airtel_money_id \|\| 'CP'` | `…'MC1'` ou `…'MC'` | `…'MC2'` |
| WEB / API / RECOVERY | `request_id \|\| '_CP'` | `…'_MC1'` ou `…'_MC'` | `…'_MC2'` |

Les sondes 01-03 et 07-13 n'ont pas besoin de cette table : elles ne regardent
que la **fin** de l'identifiant, qui est la même dans les deux cas. Seules les
sondes 04-06, qui reconstruisent l'identifiant attendu, la reprennent
intégralement.

### 5. `request.pin` ne sort jamais de la base

Interdiction explicite dans `failedBeneficiaries.js`. Les sondes ne rendent que
des nombres — la question se posera au premier qui voudra une sonde qui remonte
un détail.

---

## Avant d'enregistrer

**`?` est impraticable.** La connexion de supervision utilise
`PDO::ATTR_EMULATE_PREPARES` — le pooler Supabase ne supporte pas les requêtes
préparées nommées — donc PDO analyse le SQL lui-même et prend l'opérateur jsonb
`?` pour un paramètre positionnel. Mesuré : la requête est refusée avant
d'atteindre Postgres. D'où `jsonb_exists(response, 'error')`.

**Le cast `::` aussi.** `::timestamptz` est indistinguable d'un paramètre nommé
`:timestamptz`. On écrit `cast(x as timestamptz)`. Un premier jet de la sonde 15
en contenait deux ; le contrôle les a vus.

**`:depuis` est obligatoire**, et peut servir plusieurs fois — permis en mode
émulé, et la sonde 15 le fait.

**Une colonne nommée `valeur`, entière.** `NULL` est lu comme zéro, une absence
de ligne aussi.

**Le fuseau.** Arche calcule `:depuis` chez lui et l'envoie en texte. Si
`created_at` est un `timestamp` sans fuseau dans un autre fuseau que celui
d'Arche, le décalage est silencieux. Même test que ci-dessus : comparer au
dashboard sur la même journée.

**Un index sur `airtel_logs.created_at`** rendra les quatorze sondes de cette
table quasi gratuites. Sans lui, chacune parcourt les ~890 000 lignes, et
quatorze parcours toutes les cinq minutes se sentiront. À vérifier avant de tout
activer :

```sql
select indexname, indexdef from pg_indexes where tablename = 'airtel_logs';
```

---

## Unités et paliers

L'unité est lue **dans le corps de la notification**, qu'Arche compose ainsi :

```
{valeur} {unité} sur {fenêtre} h (palier {palier}).
```

Donc sur un écran verrouillé, la nuit, par quelqu'un sans contexte. Elle doit
faire une phrase, distinguer la sonde de ses dix-huit voisines, et nommer
l'objet métier plutôt que la table. C'est ce qui écarte « jambes » — *12 jambes
sur 24 h* ne se lit pas.

| # | Unité | 24 h | 48 h | Pourquoi ces paliers |
|---|---|---|---|---|
| 01 | `échecs CP` | `3, 10, 20, 40, 60, 100` | `10, 40, 100` | CP est la première jambe : elle casse rarement, et quand elle casse le reste suit. |
| 02 | `échecs MC1 Rengus` | `3, 10, 20, 40` | `10, 40` | Un seul marchand : la grande échelle ne sonnerait jamais. |
| 02b | `échecs MC` | `3, 10, 20, 40, 60, 100` | `10, 40, 100` | Même échelle que CP, pour que les deux chiffres se comparent à l'œil. |
| 03 | `échecs MC2 Rengus` | `3, 10, 20, 40` | `10, 40` | Idem 02. |
| 04 | `CP sans log` | `5, 20, 50, 100` | `20, 100` | 5 et non 3 : un log peut arriver en retard, et la sonde en attrape en vol. |
| 05 | `MC1 sans log` | `5, 20, 50` | `20` | Idem, à l'échelle d'un marchand. |
| 05b | `MC sans log` | `5, 20, 50, 100` | `20, 100` | Idem 04. |
| 06 | `MC2 sans log` | `5, 20, 50` | `20` | Idem 05. |
| 07 | `time-outs Airtel` | `1, 3, 10, 30` | `5, 30` | Premier palier à **1**. Un time-out isolé est déjà une information : ce n'est pas du métier qui refuse. |
| 08 | `identifiants refusés` | `1, 5, 20, 50` | `10, 50` | À 1 aussi : un identifiant refusé signale presque toujours un défaut de génération, pas un cas isolé. |
| 09 | `soldes insuffisants` | `20, 100, 300, 1000` | `100, 1000` | Le métier qui fonctionne. Paliers hauts : on guette un décrochage, pas un incident. |
| 10 | `coupures réseau` | `1, 3, 10, 30` | `5, 30` | Comme 07 : Airtel injoignable. |
| 11 | `pannes techniques inconnues` | `1, 3, 10` | `3, 10` | À 1, parce que le premier exemplaire est ce qu'on veut voir : il nomme un type qu'aucune sonde n'attrape encore. |
| 12 | `refus sans motif` | `5, 20, 50` | `20, 50` | Un refus non détaillé est aveuglant en nombre : il empêche de savoir ce qui se passe. |
| 13 | `refus métier inconnus` | `3, 10, 30, 100` | `10, 100` | À 3 : au-delà, ce n'est plus un cas isolé mais un libellé nouveau qui mérite sa propre sonde. |
| 14 | `paiements en échec` | `10, 50, 100, 250, 500` | `50, 250` | Un volume d'échecs est normal ; on guette le décrochage. |
| 15 | `minutes sans paiement` | `30, 60, 180, 360` | — | **À régler après observation** — voir ci-dessous. |
| 17 | `paiements réussis` | `100` | — | Le seuil que tu as demandé. Une seule notification tant qu'elle n'est pas acquittée — voir la note plus haut. |

### La sonde 15 mérite son propre paragraphe

Les autres comptent ce qui va mal, ou ce qui va bien. **Aucune ne se déclenche quand plus
rien n'arrive** — et c'est la panne la plus grave, parce qu'un compteur d'échecs
à zéro se lit exactement comme un système en bonne santé.

Celle-ci s'inverse : elle mesure les minutes depuis le dernier paiement et monte
toute seule quand la production s'arrête.

```sql
-- Minutes depuis le dernier paiement. La seule sonde qui monte quand plus
-- rien n'arrive — un compteur d'échecs à zéro se lit comme un système sain.
select cast(coalesce(
    extract(epoch from (now() - max(created_at))) / 60,
    extract(epoch from (now() - cast(:depuis as timestamptz))) / 60
) as int) as valeur
from payment
where created_at >= :depuis
```

Le `coalesce` n'est pas une précaution de style. Sans lui, une fenêtre
entièrement vide rend `NULL`, que `readValue` lit comme zéro : la sonde
annoncerait « tout va bien » au moment précis où plus rien n'arrive depuis
vingt-quatre heures. Le repli mesure alors depuis le début de la fenêtre, ce qui
est un minorant.

Ses paliers dépendent du rythme réel de nuit, que ce fichier ne connaît pas.
Regarde le plus long trou habituel d'un dimanche à 4 h, puis pose le premier
au-dessus. Posés trop bas, ils réveilleront quelqu'un chaque nuit — et une sonde
qu'on apprend à ignorer ne sert plus à rien.

---

## Ce qui n'est pas transcrit, et pourquoi

**Les rapports partenaires** (`partners.js`) ne décrivent pas des erreurs mais
des livrables hebdomadaires, avec leur liste de dates de test exclues en dur.
Rien à surveiller là.

**Le détail des bénéficiaires** (`failedBeneficiaries.js`) répond à « qui n'a
pas été payé », pas à « est-ce que ça va mal ». Une sonde rend un nombre ; cette
question demande une liste. Elle reste du ressort du dashboard.

**Le filtre par marchand** est absent, volontairement : une alerte doit dire que
quelque chose ne va pas, pas demander pour qui. Si un marchand doit être suivi à
part, c'est une sonde de plus — un fichier copié, une ligne changée.
