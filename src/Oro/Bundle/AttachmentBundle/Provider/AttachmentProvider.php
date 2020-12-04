<?php

namespace Oro\Bundle\AttachmentBundle\Provider;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManager;
use Oro\Bundle\AttachmentBundle\Entity\Attachment;
use Oro\Bundle\AttachmentBundle\Entity\File;
use Oro\Bundle\AttachmentBundle\Manager\AttachmentManager;
use Oro\Bundle\AttachmentBundle\Tools\AttachmentAssociationHelper;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Component\PhpUtils\Formatter\BytesFormatter;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Provides attachments linked to an entity.
 */
class AttachmentProvider
{
    /** @var EntityManager */
    protected $em;

    /** @var AttachmentAssociationHelper */
    protected $attachmentAssociationHelper;

    /** @var AttachmentManager */
    private $attachmentManager;

    /**
     * @param EntityManager               $entityManager
     * @param AttachmentAssociationHelper $attachmentAssociationHelper
     * @param AttachmentManager           $attachmentManager
     */
    public function __construct(
        EntityManager $entityManager,
        AttachmentAssociationHelper $attachmentAssociationHelper,
        AttachmentManager $attachmentManager
    ) {
        $this->em                          = $entityManager;
        $this->attachmentAssociationHelper = $attachmentAssociationHelper;
        $this->attachmentManager           = $attachmentManager;
    }

    /**
     * @param object $entity
     *
     * @return Attachment[]
     */
    public function getEntityAttachments($entity)
    {
        $entityClass = ClassUtils::getClass($entity);
        if ($this->attachmentAssociationHelper->isAttachmentAssociationEnabled($entityClass)) {
            $fieldName = ExtendHelper::buildAssociationName($entityClass);
            $repo = $this->em->getRepository('OroAttachmentBundle:Attachment');

            $qb = $repo->createQueryBuilder('a');
            $qb->leftJoin('a.' . $fieldName, 'entity')
                ->where('entity.id = :entityId')
                ->setParameter('entityId', $entity->getId());

            return $qb->getQuery()->getResult();
        }

        return [];
    }

    /**
     * @param $entity
     *
     * @return File
     */
    private function getAttachmentByEntity($entity)
    {
        $accessor   = PropertyAccess::createPropertyAccessor();
        $attachment = $accessor->getValue($entity, 'attachment');

        return $attachment;
    }

    /**
     * @param $entity
     *
     * @return array
     */
    public function getAttachmentInfo($entity)
    {
        $result     = [];
        $attachment = $this->getAttachmentByEntity($entity);
        if ($attachment && $attachment->getId()) {
            $thumbnail = '';
            if ($this->attachmentManager->isImageType($attachment->getMimeType())) {
                $thumbnail = $this->attachmentManager->getResizedImageUrl(
                    $attachment,
                    AttachmentManager::THUMBNAIL_WIDTH,
                    AttachmentManager::THUMBNAIL_HEIGHT
                );
            }
            $result = [
                'attachmentURL'       => $this->attachmentManager
                    ->getFileUrl($attachment, FileUrlProviderInterface::FILE_ACTION_DOWNLOAD),
                'attachmentSize'      => BytesFormatter::format($attachment->getFileSize()),
                'attachmentFileName'  => $attachment->getOriginalFilename() ?: $attachment->getFilename(),
                'attachmentIcon'      => $this->attachmentManager->getAttachmentIconClass($attachment),
                'attachmentThumbnail' => $thumbnail
            ];
        }

        return $result;
    }
}
