<?php

namespace Ferranfg\MidjourneyPhp;

use Exception;
use GuzzleHttp\Client;

class Midjourney
{

    private const API_URL = 'https://discord.com/api/v9';

    protected const APPLICATION_ID = '936929561302675456';

    protected const DATA_ID = '938956540159881230';

    protected const DATA_VERSION = '1118961510123847772';

    protected const SESSION_ID = '2fb980f65e5c9a77c96ca01f2c242cf6';

    private $client;

    private $channel_id;

    private $thread_id;

    private $oauth_token;

    private $guild_id;

    private $user_id;

    public function __construct($channel_id, $oauth_token)
    {
        $this->channel_id = $channel_id;
        $this->oauth_token = $oauth_token;

        $this->client = new Client([
            'base_uri' => self::API_URL,
            'headers' => [
                'Authorization' => $this->oauth_token
            ]
        ]);

        $request = $this->client->get('channels/' . $this->channel_id);
        $response = json_decode((string)$request->getBody());

        $this->guild_id = $response->guild_id;

        $request = $this->client->get('users/@me');
        $response = json_decode((string)$request->getBody());

        $this->user_id = $response->id;
    }

    private static function firstWhere($array, $key, $value = null)
    {
        foreach ($array as $item) {
            if (
                (is_callable($key) and $key($item)) or
                (is_string($key) and str_starts_with($item->{$key}, $value))
            ) {
                return $item;
            }
        }

        return null;
    }

    private static function allWhere($array, $key, $value = null)
    {
        $result = array();
        foreach ($array as $item) {
            if (
                (is_callable($key) and $key($item)) or
                (is_string($key) and str_starts_with($item->{$key}, $value))
            ) {
                $result[] = $item;
            }
        }

        return $result;
    }

    public function imagine(string $prompt)
    {
        $this->sendImagine($prompt);

        sleep(8);

        $imagine_message = null;

        while (is_null($imagine_message)) {
            $imagine_message = $this->getImagine($prompt);

            if (is_null($imagine_message)) sleep(8);
        }

        return $imagine_message;
    }

    public function sendImagine(string $prompt) {
        $params = [
            'type' => 2,
            'application_id' => self::APPLICATION_ID,
            'guild_id' => $this->guild_id,
            'channel_id' => $this->channel_id,
            'session_id' => self::SESSION_ID,
            'data' => [
                'version' => self::DATA_VERSION,
                'id' => self::DATA_ID,
                'name' => 'imagine',
                'type' => 1,
                'options' => [[
                    'type' => 3,
                    'name' => 'prompt',
                    'value' => $prompt
                ]],
                'application_command' => [
                    'id' => self::DATA_ID,
                    'application_id' => self::APPLICATION_ID,
                    'version' => self::DATA_VERSION,
                    'default_member_permissions' => null,
                    'type' => 1,
                    'nsfw' => false,
                    'name' => 'imagine',
                    'description' => 'Create images with Midjourney',
                    'dm_permission' => true,
                    'options' => [[
                        'type' => 3,
                        'name' => 'prompt',
                        'description' => 'The prompt to imagine',
                        'required' => true
                    ]]
                ],
                'attachments' => []
            ]
        ];

        $this->client->post('interactions', [
            'json' => $params
        ]);
    }

    public function getImagine(string $prompt, string $channel_id = null)
    {
        $response = $this->client->get('channels/' . !empty($channel_id) ? $channel_id : $this->channel_id . '/messages');
        $response = json_decode((string)$response->getBody());

        $raw_message = self::firstWhere($response, function ($item) use ($prompt) {
            return (
                str_starts_with($item->content, "**{$prompt}** - <@" . $this->user_id . '>') and
                !str_contains($item->content, '%') and
                str_ends_with($item->content, '(fast)')
            );
        });

        if (is_null($raw_message)) return null;

        return (object)[
            'id' => $raw_message->id,
            'prompt' => $prompt,
            'raw_message' => $raw_message
        ];
    }

    public function getMessagesByPromt(string $prompt, callable $search)
    {
        $response = $this->client->get('channels/' . $this->channel_id . '/messages');
        $response = json_decode((string)$response->getBody());

        $raw_messages = self::allWhere($response, $search);

        return (object)[
            'prompt' => $prompt,
            'raw_messages' => $raw_messages
        ];
    }

    public function upscale($message, int $upscale_index = 0)
    {
        if (!property_exists($message, 'raw_message')) {
            throw new Exception('Upscale requires a message object obtained from the imagine/getImagine methods.');
        }

        if ($upscale_index < 0 or $upscale_index > 3) {
            throw new Exception('Upscale index must be between 0 and 3.');
        }

        $upscale_hash = null;
        $raw_message = $message->raw_message;

        if (property_exists($raw_message, 'components') and is_array($raw_message->components)) {
            $upscales = $raw_message->components[0]->components;

            $upscale_hash = $upscales[$upscale_index]->custom_id;
        }

        $params = [
            'type' => 3,
            'guild_id' => $this->guild_id,
            'channel_id' => $this->channel_id,
            'message_flags' => 0,
            'message_id' => $message->id,
            'application_id' => self::APPLICATION_ID,
            'session_id' => self::SESSION_ID,
            'data' => [
                'component_type' => 2,
                'custom_id' => $upscale_hash
            ]
        ];

        $this->client->post('interactions', [
            'json' => $params
        ]);

        $upscaled_photo_url = null;

        while (is_null($upscaled_photo_url)) {
            $upscaled_photo_url = $this->getUpscale($message, $upscale_index);

            if (is_null($upscaled_photo_url)) sleep(3);
        }

        return $upscaled_photo_url;
    }

    public function getUpscale($message, $upscale_index = 0)
    {
        if (!property_exists($message, 'raw_message')) {
            throw new Exception('Upscale requires a message object obtained from the imagine/getImagine methods.');
        }

        if ($upscale_index < 0 or $upscale_index > 3) {
            throw new Exception('Upscale index must be between 0 and 3.');
        }

        $prompt = $message->prompt;

        $response = $this->client->get('channels/' . $this->channel_id . '/messages');
        $response = json_decode((string)$response->getBody());

        $message_index = $upscale_index + 1;
        $message = self::firstWhere($response, 'content', "**{$prompt}** - Image #{$message_index} <@" . $this->user_id . '>');

        if (is_null($message)) {
            $message = self::firstWhere($response, 'content', "**{$prompt}** - Upscaled by <@" . $this->user_id . '> (fast)');
        }

        if (is_null($message)) return null;

        if (property_exists($message, 'attachments') and is_array($message->attachments)) {
            $attachment = $message->attachments[0];

            return $attachment->url;
        }

        return null;
    }

    public function generate($prompt, $upscale_index = 0)
    {
        $imagine = $this->imagine($prompt);

        $upscaled_photo_url = $this->upscale($imagine, $upscale_index);

        return (object)[
            'imagine_message_id' => $imagine->id,
            'upscaled_photo_url' => $upscaled_photo_url
        ];
    }

    public function startTread($name)
    {
        $channel = $this->client->request('POST', self::API_URL . '/channels/' . $this->channel_id . '/threads', [
            'headers' => [
                'Content-Type' => 'application/json'
                , 'Authorization: ' . $this->oauth_token],
            'body' => json_encode([
                'name' => $name,
                'type' => 11
            ])
        ]);

        if ($channel->getStatusCode() == 200 || $channel->getStatusCode() == 201) {
            return json_decode((string)$channel->getBody());
        }

        return false;
    }
}