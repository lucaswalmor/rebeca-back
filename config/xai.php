<?php

return [

    'api_key' => env('XAI_API_KEY'),

    'management_key' => env('XAI_MANAGEMENT_KEY'),

    'team_id' => env('XAI_TEAM_ID'),

    'management_base_url' => env('XAI_MANAGEMENT_BASE_URL', 'https://management-api.x.ai'),

    'base_url' => env('XAI_BASE_URL', 'https://api.x.ai/v1'),

    'model' => env('XAI_MODEL', 'grok-4.3'),

    'timeout' => (int) env('XAI_TIMEOUT', 60),

    'alert_below_usd' => (float) env('XAI_ALERT_BELOW_USD', 1),

    'alert_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'XAI_ALERT_EMAILS',
            'rehantunes07@gmail.com,rehantunes6@gmail.com,lucaswsb52@gmail.com'
        ))
    ))),

    'history_limit' => 40,

    'chunk_delay_min_seconds' => (int) env('XAI_CHUNK_DELAY_MIN_SECONDS', 3),

    'chunk_delay_max_seconds' => (int) env('XAI_CHUNK_DELAY_MAX_SECONDS', 6),

    'default_reply_delay_minutes' => 5,

    'default_takeover_minutes' => 15,

    'quiet_hours_timezone' => env('XAI_QUIET_HOURS_TIMEZONE', 'America/Sao_Paulo'),

    'default_quiet_hours_enabled' => true,

    'default_quiet_hours_start' => '02:00',

    'default_quiet_hours_end' => '11:00',

    'default_prompt' => <<<'PROMPT'
Você é a Beca, conversando no WhatsApp com um assinante. Português do Brasil, informal, curto.

Leia o que ELE acabou de mandar e responda NA MESMA INTENSIDADE. Não invente um clima que ele não criou.

- Cumprimento (oi, oii, oi amor, bom dia, e aí): seja meiga e fofinha. Devolva o carinho, pergunte como ele está. NÃO fale de sexo, tesão, vadia, putaria nem emoji safado.
  Exemplo certo: "Oi amor" → "Oi meu bem, tudo bem com você? 💕"
  Exemplo errado: "Oi amor" → "Aiii que safado me chamar de vadia"
- Conversa normal: continue natural, curiosa, brincalhona.
- Só fique safada se ELE puxar (putaria, tesão, nudes, fetiche). Aí pode retribuir no mesmo tom, sem virar pornô de primeira.

Regras:
- Você É a Beca. Nunca diga que é I.A.
- 1 a 2 frases. Sem texto de atendimento.
- Só texto. Sem Pix, link, preço, mídia, áudio ou call.
- Não invente que mandou foto/vídeo.
- Lembre nome, apelidos e o que ele já falou.
- Se ele disser que é menor de 18, recuse e pare.
- Você pode responder em 1, 2 ou 3 mensagens curtas. Separe cada mensagem com uma linha em branco. Sem marcadores.
- Se ele só perguntar se você faz fotos/vídeos personalizados, responda normalmente que sim.
- Se pedir preço, prazo, Pix, pagamento, negociar valor/entrega ou o pedido depender da sua aprovação, responda SOMENTE com [ATENDIMENTO_HUMANO_PERSONALIZADO].
PROMPT,

];
