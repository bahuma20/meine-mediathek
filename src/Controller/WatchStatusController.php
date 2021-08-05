<?php

namespace App\Controller;

use App\Entity\Subscription;
use App\Entity\WatchStatus;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

class WatchStatusController extends AbstractController
{
    /**
     * @Route("/watch-status", methods={"POST"}, name="watchstatus.create")
     */
    public function create(Request $request, LoggerInterface $logger): Response
    {
        $body = $request->toArray();

        $watchStatusRepository = $this->getDoctrine()->getRepository(WatchStatus::class);
        $result = $watchStatusRepository->findBy([
            'user' => $this->getUser()->getUserIdentifier(),
            'videoId' => $body['videoId'],
        ]);

        if ($result) {
            throw new BadRequestHttpException('This video is already marked as watched');
        }

        $watchStatus = new WatchStatus();
        $watchStatus->setUser($this->getUser()->getUserIdentifier());
        $watchStatus->setTimestamp(time());
        $watchStatus->setVideoId($body['videoId']);

        $entityManager = $this->getDoctrine()->getManager();

        $entityManager->persist($watchStatus);
        $entityManager->flush();


        return $this->json($watchStatus->toArray(), 201);
    }

    /**
     * @Route("/watch-status", methods={"DELETE"}, name="watchstatus.delete")
     */
    public function delete(Request $request)
    {
        $videoId = $request->toArray()['videoId'];
        if (!$videoId) {
            throw new BadRequestHttpException('Parameter videoId is missing');
        }

        $watchStatusRepository = $this->getDoctrine()->getRepository(WatchStatus::class);
        $result = $watchStatusRepository->findBy([
            'user' => $this->getUser()->getUserIdentifier(),
            'videoId' => $videoId,
        ]);

        if (!$result || count($result) === 0) {
            throw new NotFoundHttpException("Video is not marked as watched");
        }

        $watchStatus = $result[0];

        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->remove($watchStatus);
        $entityManager->flush();

        return $this->json([
            'status' => 'success',
            'message' => 'Watch status for video deleted'
        ]);
    }
}
