-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : N'IMPORTE LAQUELLE — c'est justement la question          ║
-- ╚═══════════════════════════════════════════════════════════════════╝
--
-- Sur quelle base suis-je connecté ?
--
-- Les deux bases s'appellent `postgres` et leurs éditeurs se ressemblent. Le
-- nom ne distingue donc rien : ce qui distingue, c'est ce qu'on y trouve.
--
-- `to_regclass` rend l'identifiant d'une table, ou NULL si elle n'existe pas.
-- Il ne lève pas d'erreur, ce qui permet d'interroger les deux d'un coup.

select
    current_database()                        as base,
    current_user                              as compte,
    case
        when to_regclass('public.monitoring_probes') is not null
             and to_regclass('public.airtel_logs') is not null
            then 'LES DEUX ?! — configuration inattendue, dites-le moi'
        when to_regclass('public.monitoring_probes') is not null
            then 'ARCHE — c''est ici que vont les fichiers api/database/sql/'
        when to_regclass('public.airtel_logs') is not null
            then 'PAYNALA — c''est ici que vont les fichiers sondes/'
        else 'NI L''UNE NI L''AUTRE — ni monitoring_probes ni airtel_logs'
    end                                       as verdict,
    to_regclass('public.monitoring_probes')    as table_arche,
    to_regclass('public.airtel_logs')          as table_paynala,
    to_regclass('public.users')                as users,
    to_regclass('public.payment')              as payment;
