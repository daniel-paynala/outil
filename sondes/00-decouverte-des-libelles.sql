with jambes as (
    select
        v.jambe,
        case
            when v.request_id is null then 'MISSING'
            when v.response->'status'->>'success' = 'true' then 'SUCCESS'
            else 'FAILED'
        end as statut,
        case
            when v.request_id is null then 'Log Airtel absent'
            when v.response->'status'->>'success' = 'true' then null
            when jsonb_exists(v.response, 'error') then
                v.response->>'error' ||
                case when v.response->>'message' is not null
                     then ' \u2014 ' || (v.response->>'message') else '' end
            else coalesce(
                v.response->'status'->>'message',
                '\u00c9chec non d\u00e9taill\u00e9' || case when v.http_code is not null
                     then ' (HTTP ' || v.http_code || ')' else '' end
            )
        end as raison,
        jsonb_exists(v.response, 'error') as infrastructure
    from payment p
    join merchant m on m.id = p.merchant_id
    left join lateral (
        select al.request_id, al.http_code, al.response
        from airtel_logs al
        where al.request_id =
            case
                when p.channel = 'USSD' then p.airtel_money_id || 'CP'
                else p.request_id || '_CP'
            end
        order by al.created_at desc
        limit 1
    ) cp on true
    left join lateral (
        select al.request_id, al.http_code, al.response
        from airtel_logs al
        where al.request_id =
            case
                when m.name = 'Rengus Digital' then
                    case when p.channel = 'USSD' then p.airtel_money_id || 'MC1'
                         else p.request_id || '_MC1' end
                else
                    case when p.channel = 'USSD' then p.airtel_money_id || 'MC'
                         else p.request_id || '_MC' end
            end
        order by al.created_at desc
        limit 1
    ) mc1 on true
    left join lateral (
        select al.request_id, al.http_code, al.response
        from airtel_logs al
        where m.name = 'Rengus Digital'
          and al.request_id =
            case
                when p.channel = 'USSD' then p.airtel_money_id || 'MC2'
                else p.request_id || '_MC2'
            end
        order by al.created_at desc
        limit 1
    ) mc2 on true
    cross join lateral (values
        ('CP', cp.request_id, cp.http_code, cp.response, true),
        (case when m.name = 'Rengus Digital' then 'MC1' else 'MC' end,
         mc1.request_id, mc1.http_code, mc1.response, true),
        ('MC2', mc2.request_id, mc2.http_code, mc2.response,
         m.name = 'Rengus Digital')
    ) as v(jambe, request_id, http_code, response, concernee)
    where p.created_at >= now() - interval '30 days'
      and p.channel in ('WEB', 'USSD', 'API', 'RECOVERY')
      and p.status = 'SUCCESS'
      and v.concernee
)
select
    raison,
    count(*) as occurrences,
    count(distinct jambe) as jambes_touchees
from jambes
where statut = 'FAILED'
group by raison
order by occurrences desc;
