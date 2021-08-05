<?php

namespace App\Service;

use App\Model\Video;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MediathekView
{
    protected HttpClientInterface $httpClient;
    protected LoggerInterface $logger;

    public function __construct(HttpClientInterface $httpClient, LoggerInterface $logger)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    /**
     * Load newest videos from MediathekView.
     *
     * @param string $topic
     *   The topic (For example "Ringlstetter")
     * @param string|null $channel
     *   The channel (For example "BR")
     * @param int|null $duration
     *   The minimum duration of the video in minutes.
     *
     * @return Video[]
     */
    public function getVideos(string $topic, string $channel = NULL, int $duration = NULL): array
    {
        try {
            $body = [
                'queries' => [],
                'sortBy' => 'timestamp',
                'sortOrder' => 'desc',
                'future' => false,
                'offset' => 0,
                'size' => 25,
            ];

            $body['queries'][] = [
                'fields' => [
                    'topic'
                ],
                'query' => $topic,
            ];

            if ($channel) {
                $body['queries'][] = [
                    'fields' => [
                        'channel'
                    ],
                    'query' => $channel,
                ];
            }

            if ($duration) {
                $body['duration_min'] = $duration * 60;
            }

            $response = $this->httpClient->request('POST', 'https://mediathekviewweb.de/api/query', [
                'headers' => [
                    'Content-Type' => 'text/plain',
                ],
                'body' => json_encode($body),
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error($response->getContent());
                throw new ServiceUnavailableHttpException(NULL, "Could not load subscription");
            }

            $data = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR);

            if (empty($data->result->results)) {
                throw new \Exception('Response does not contain the result->results path');
            }

        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
            return [];
        }

        $videos = [];
        foreach ($data->result->results as $item) {
            $video = new Video();
            $video->id = $item->id;
            $video->channel = $item->channel;
            $video->topic = $item->topic;
            $video->title = $item->title;
            $video->description = $item->description;
            $video->duration = (int)$item->duration;
            $video->timestamp = (int)$item->timestamp;
            $video->urlVideo = $item->url_video_hd;
            $video->urlVideoWebsite = $item->url_website;
            $videos[] = $video;
        }

        return $videos;
    }
}
