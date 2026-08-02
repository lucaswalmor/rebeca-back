<?php

namespace App\Services;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\RoomServiceClient;
use Agence104\LiveKit\VideoGrant;
use App\Models\Live;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class LiveKitService
{
    public function wsUrl(): string
    {
        return rtrim((string) config('services.livekit.url'), '/');
    }

    public function apiHost(): string
    {
        $host = (string) config('services.livekit.api_url');
        if ($host !== '') {
            return rtrim($host, '/');
        }

        $ws = $this->wsUrl();
        $https = preg_replace('#^wss:#', 'https:', $ws);
        $https = preg_replace('#^ws:#', 'http:', (string) $https);

        return rtrim((string) $https, '/');
    }

    public function createToken(Live $live, User $user, bool $canPublish): string
    {
        $apiKey = (string) config('services.livekit.api_key');
        $apiSecret = (string) config('services.livekit.api_secret');

        if ($apiKey === '' || $apiSecret === '') {
            throw new \RuntimeException('LiveKit não configurado. Defina LIVEKIT_API_KEY e LIVEKIT_API_SECRET.');
        }

        $identity = 'user-'.$user->id;
        $name = $user->apelido ?: $user->nome ?: $identity;

        $options = (new AccessTokenOptions)
            ->setIdentity($identity)
            ->setName($name)
            ->setTtl(6 * 60 * 60)
            ->setMetadata(json_encode([
                'user_id' => $user->id,
                'is_admin' => $user->isAdmin(),
                'role' => $canPublish ? 'host' : 'viewer',
            ], JSON_UNESCAPED_UNICODE));

        $grant = (new VideoGrant)
            ->setRoomJoin()
            ->setRoomName($live->livekit_room)
            ->setCanSubscribe()
            ->setCanPublishData()
            ->setCanPublish($canPublish);

        return (new AccessToken($apiKey, $apiSecret, $options))
            ->setGrant($grant)
            ->toJwt();
    }

    public function removeParticipant(Live $live, User $user): void
    {
        try {
            $client = $this->roomClient();
            $client->removeParticipant($live->livekit_room, 'user-'.$user->id);
        } catch (\Throwable $e) {
            Log::warning('LiveKit removeParticipant falhou', [
                'live_id' => $live->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function roomClient(): RoomServiceClient
    {
        $apiKey = (string) config('services.livekit.api_key');
        $apiSecret = (string) config('services.livekit.api_secret');

        return new RoomServiceClient($this->apiHost(), $apiKey, $apiSecret);
    }
}
