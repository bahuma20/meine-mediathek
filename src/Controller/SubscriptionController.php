<?php

namespace App\Controller;

use App\Entity\Subscription;
use App\Service\MediathekView;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

class SubscriptionController extends AbstractController
{
    /**
     * @Route("/subscriptions", methods={"GET"}, name="subscriptions.list")
     */
    public function list(): Response
    {
        $subscriptionRepository = $this->getDoctrine()->getRepository(Subscription::class);

        $subscriptions = $subscriptionRepository->findBy([
            'user' => $this->getUser()->getUserIdentifier(),
        ]);

        return $this->json(array_map(function ($subscription) {
            return $subscription->toArray();
        }, $subscriptions));
    }

    /**
     * @Route("/subscriptions/{subscriptionId}", methods={"GET"}, name="subscriptions.get")
     */
    public function getSubscription(string $subscriptionId): Response
    {
        $subscription = $this->loadSubscriptionById($subscriptionId);

        return $this->json($subscription->toArray());
    }

    /**
     * @Route("/subscriptions", methods={"POST"}, name="subscriptions.create")
     */
    public function create(Request $request, LoggerInterface $logger): Response
    {
        $body = $request->toArray();

        $subscription = new Subscription();
        $subscription->setUser($this->getUser()->getUserIdentifier());
        if (!empty($body['channel'])) {
            $subscription->setChannel($body['channel']);
        }
        if (!empty($body['topic'])) {
            $subscription->setTopic($body['topic']);
        }
        if (!empty($body['duration'])) {
            $subscription->setDuration($body['duration']);
        }

        $entityManager = $this->getDoctrine()->getManager();

        $entityManager->persist($subscription);
        $entityManager->flush();

        $this->clearFeedCache();

        return $this->json($subscription->toArray(), 201);
    }

    /**
     * @Route("/subscriptions/{subscriptionId}", methods={"PUT"}, name="subscriptions.update")
     */
    public function update(string $subscriptionId, Request $request): Response
    {
        $body = $request->toArray();

        $subscription = $this->loadSubscriptionById($subscriptionId);

        $topic = empty($body['topic']) ? NULL : $body['topic'];
        $channel = empty($body['channel']) ? NULL : $body['channel'];
        $duration = empty($body['duration']) ? NULL : $body['duration'];

        if ($topic == NULL) {
            throw new BadRequestHttpException('Missing required parameter: "topic"');
        }

        $subscription->setTopic($topic);
        $subscription->setChannel($channel);
        $subscription->setDuration($duration);

        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->persist($subscription);
        $entityManager->flush();

        $this->clearFeedCache();

        return $this->json([
            'status' => 'success',
        ]);
    }

    /**
     * @Route("/subscriptions/{subscriptionId}", methods={"DELETE"}, name="subscriptions.delete")
     */
    public function delete(string $subscriptionId): Response
    {
        $subscription = $this->loadSubscriptionById($subscriptionId);

        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->remove($subscription);
        $entityManager->flush();

        $this->clearFeedCache();

        return $this->json([
            'status' => 'success',
        ]);
    }

    /**
     * @Route("/subscriptions/preview", methods={"POST"}, name="subscriptions.preview")
     */
    public function preview(Request $request, MediathekView $mediathekView): Response
    {
        $body = $request->toArray();
        $topic = empty($body['topic']) ? NULL : $body['topic'];
        $channel = empty($body['channel']) ? NULL : $body['channel'];
        $duration = empty($body['duration']) ? NULL : $body['duration'];

        if ($topic == NULL) {
            throw new BadRequestHttpException('Missing required parameter: "topic"');
        }

        $videos = $mediathekView->getVideos($topic, $channel, $duration);

        return $this->json([
            'videos' => $videos,
        ]);
    }

    /**
     * Loads a subscription and validates if the user has access to manipulate it.
     *
     * @param string $subscriptionId
     *
     * @return Subscription
     *
     * @throws NotFoundHttpException
     * @throws AccessDeniedHttpException;
     */
    protected function loadSubscriptionById(string $subscriptionId): Subscription {
        $subscriptionRepository = $this->getDoctrine()->getRepository(Subscription::class);
        $subscription = $subscriptionRepository->findOneBy([
            'id' => $subscriptionId,
        ]);

        if (!$subscription) {
            throw new NotFoundHttpException('No subscription with this id found');
        }

        if ($subscription->getUser() !== $this->getUser()->getUserIdentifier()) {
            throw new AccessDeniedHttpException('You don\'t have access to update this subscription');
        }

        return $subscription;
    }


    /**
     * Flush the video feed cache for the current user.
     */
    protected function clearFeedCache()
    {
        $cache = new FilesystemAdapter();

        try {
            $cache->delete('feed_videos_' . $this->getUser()->getUserIdentifier());
        } catch (InvalidArgumentException $e) {
            // That's fine. If the cache doesn't exist, we don't have to clear it.
        }
    }
}
