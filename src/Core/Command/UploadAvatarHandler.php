<?php

/*
 * This file is part of Flarum.
 *
 * (c) Toby Zerner <toby.zerner@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Flarum\Core\Command;

use Flarum\Core\Access\AssertPermissionTrait;
use Flarum\Core\AvatarUploader;
use Flarum\Core\Repository\UserRepository;
use Flarum\Core\Support\DispatchEventsTrait;
use Flarum\Core\Validator\AvatarValidator;
use Flarum\Event\AvatarWillBeSaved;
use Flarum\Foundation\Application;
use Illuminate\Contracts\Events\Dispatcher;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class UploadAvatarHandler
{
    use DispatchEventsTrait;
    use AssertPermissionTrait;

    /**
     * @var UserRepository
     */
    protected $users;

    /**
     * @var Application
     */
    protected $app;

    /**
     * @var AvatarUploader
     */
    protected $uploader;

    /**
     * @var AvatarValidator
     */
    protected $validator;

    /**
     * @param Dispatcher $events
     * @param UserRepository $users
     * @param Application $app
     * @param AvatarUploader $uploader
     * @param AvatarValidator $validator
     */
    public function __construct(Dispatcher $events, UserRepository $users, Application $app, AvatarUploader $uploader, AvatarValidator $validator)
    {
        $this->events = $events;
        $this->users = $users;
        $this->app = $app;
        $this->uploader = $uploader;
        $this->validator = $validator;
    }

    /**
     * @param UploadAvatar $command
     * @return \Flarum\Core\User
     * @throws \Flarum\Core\Exception\PermissionDeniedException
     */
    public function handle(UploadAvatar $command)
    {
        $actor = $command->actor;

        $user = $this->users->findOrFail($command->userId);

        if ($actor->id !== $user->id) {
            $this->assertCan($actor, 'edit', $user);
        }

        $file = $command->file;

        $tmpFile = tempnam($this->app->storagePath().'/tmp', 'avatar');
        $file->moveTo($tmpFile);

        try {
            $file = new UploadedFile(
                $tmpFile,
                $file->getClientFilename(),
                $file->getClientMediaType(),
                $file->getSize(),
                $file->getError(),
                true
            );

            $this->validator->assertValid(['avatar' => $file]);

            $image = (new ImageManager)->make($tmpFile);

            $this->events->fire(
                new AvatarWillBeSaved($user, $actor, $image)
            );

            $this->uploader->upload($user, $image);

            $user->save();
        } finally {
            @unlink($tmpFile);
        }

        return $user;
    }
}
