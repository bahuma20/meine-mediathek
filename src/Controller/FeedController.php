<?php

namespace App\Controller;

use App\Entity\Subscription;
use App\Entity\WatchStatus;
use App\Service\MediathekView;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\ItemInterface;

class FeedController extends AbstractController
{
    protected MediathekView $mediathekView;
    protected LoggerInterface $logger;

    public function __construct(MediathekView $mediathekView, LoggerInterface $logger)
    {
        $this->mediathekView = $mediathekView;
        $this->logger = $logger;
    }


    /**
     * @Route("/feed", name="feed")
     */
    public function feed(Request $request): Response
    {
        $limit = $request->query->get('limit') ?: 20;
        $offset = $request->query->get('offset') ?: 0;

        // TODO: Reduce number of items per subscription if subscriptions are too many...

        $cache = new FilesystemAdapter();

        // Delete cached version if refresh is forced.
        if ($request->query->has('force_refresh')) {
            try {
                $cache->delete('feed_videos_' . $this->getUser()->getUserIdentifier());
            } catch (\InvalidArgumentException $e) {
                // This is fine. If the cache does not exist, we dont have to clear it.
            }
        }

        $videos = $cache->get('feed_videos_' . $this->getUser()->getUserIdentifier(), function(ItemInterface $item) {
            // Invalidate the caches every 15 minutes
            $item->expiresAfter(60*15);

            $this->logger->debug('Fetching video data from server');

            // Get all subscriptions of the user.
            $subscriptionRepository = $this->getDoctrine()->getRepository(Subscription::class);

            $subscriptions = $subscriptionRepository->findBy([
                'user' => $this->getUser()->getUserIdentifier(),
            ]);

            // Load the 25 newest videos for every subscription.
            $videos = [];

            foreach ($subscriptions as $subscription) {
                $subscriptionVideos = $this->mediathekView->getVideos($subscription->getTopic(), $subscription->getChannel(), $subscription->getDuration());
                $videos = array_merge($videos, $subscriptionVideos);
            }

            return $videos;
        });

        // Filter out watched videos.
        $videoIds = array_map(function($item) { return $item->id;}, $videos);
        $watchStatusRepository = $this->getDoctrine()->getRepository(WatchStatus::class);
        $watchStatuses = $watchStatusRepository->findBy([
            'videoId' => $videoIds,
        ]);
        $watchedVideosIds = array_map(function($item) {return $item->getVideoId();}, $watchStatuses);

        $videos = array_filter($videos, function($item) use ($watchedVideosIds) {
            return !in_array($item->id, $watchedVideosIds);
        });

        // Sort by timestamp.
        usort($videos, function ($a, $b) {
            return $b->timestamp - $a->timestamp;
        });

        // Pagination.
        $videos = array_splice($videos, $offset, $limit);

        return $this->json([
            'videos' => $videos,
        ]);
    }

}
