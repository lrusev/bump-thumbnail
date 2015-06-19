<?php
namespace Bump\ThumbnailBundle\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\DependencyInjection\ContainerAware;
use Bump\ThumbnailBundle\Thumbnail\Generator;
use Bump\ThumbnailBundle\Thumbnail\Source;

class CallbackController extends ContainerAware
{
    /**
     * @Route("/save/{hash}", name="bump_thumbnailer_save")
     * @Method({"POST"})
     */
    public function saveAction($hash, Request $request)
    {
        $logger = $this->container->get('logger');
        $encryptor = $this->container->get('bump_api.encryptor');

        $logger->info("Thumnaler.save.start");

        $params = json_decode($encryptor->decrypt($hash), true);
        if ($params &&
            isset($params[Generator::SAVE_PARAM]) &&
            isset($params[Generator::COPY_EXT]) &&
            isset($params[Generator::ID_PARAM]) &&
            isset($params[Generator::GROUP_PARAM])) {
            $logger->info("Thumnaler.save success", ['data' => $params, 'hash' => $hash]);
            $data = json_decode($request->getContent(), true);
            // $data = json_decode(file_get_contents('/var/www/bumps/pdc_cyberi2/branches/case_77851/var/cache/data.json'), true);
            // file_put_contents('/var/www/bumps/pdc_cyberi2/branches/case_77851/var/cache/data.json', $request->getContent());
            // file_put_contents('/var/www/bumps/pdc_cyberi2/branches/case_77851/var/cache/hash.txt', $hash);
            if (isset($data['response']['result']) && $data['response']['result']) {
                $content = base64_decode($data['response']['data']['content']);
                $savePath = $params[Generator::SAVE_PARAM];

                file_put_contents($savePath, $content);
                $logger->info("Thumnaler.save success save thumnail at ".$savePath);
                $copy = $savePath.'.'.$params[Generator::COPY_EXT];
                if (file_exists($copy)) {
                    @unlink($copy);
                }

                $defaultThumbnails = $this->container->getParameter('bump_thumbnail.default_thumbnails');
                if (empty($defaultThumbnails)) {
                    return new Response(null, 200);
                }

                header("HTTP/1.1 204 No Content");
                header('Content-Length: 0');
                header('Connection: close');
                flush();

                $thumbnailer = $this->container->get('bump_thumbnail.generator');

                $source = new Source($savePath, $params[Generator::ID_PARAM], null, $params[Generator::GROUP_PARAM]);
                $thumbnailer->generateThumbnails($source, $defaultThumbnails);

                return new Response(null, 200);
            } else {
                if (is_null($data)) {
                    $data = [];
                }

                if (isset($data['response']['data']['content'])) {
                    $data['response']['data']['content'] = substr($data['response']['data']['content'], 0, 50);
                }

                $logger->warn("Thumnaler.save invalid request", $data);

                return new JsonResponse(["message" => "Invalid request data"], 400);
            }
        }

        $logger->warn("Thumnaler.save invalid hash", ['data' => $params, 'hash' => $hash]);

        return new JsonResponse(["message" => "Invalid callback hash specified"], 400);
    }
}
