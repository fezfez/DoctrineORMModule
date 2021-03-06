<?php

declare(strict_types=1);

namespace DoctrineORMModule\Form\Annotation;

use ArrayObject;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;
use DoctrineModule\Form\Element;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Form\Annotation\AnnotationBuilder as LaminasAnnotationBuilder;

use function class_exists;
use function get_class;
use function in_array;
use function is_object;

class AnnotationBuilder extends LaminasAnnotationBuilder
{
    public const EVENT_CONFIGURE_FIELD       = 'configureField';
    public const EVENT_CONFIGURE_ASSOCIATION = 'configureAssociation';
    public const EVENT_EXCLUDE_FIELD         = 'excludeField';
    public const EVENT_EXCLUDE_ASSOCIATION   = 'excludeAssociation';

    /** @var ObjectManager */
    protected $objectManager;

    /**
     * Constructor. Ensures ObjectManager is present.
     */
    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * {@inheritDoc}
     */
    public function setEventManager(EventManagerInterface $events)
    {
        parent::setEventManager($events);

        (new ElementAnnotationsListener($this->objectManager))->attach($this->getEventManager());

        return $this;
    }

    /**
     * Overrides the base getFormSpecification() to additionally iterate through each
     * field/association in the metadata and trigger the associated event.
     *
     * This allows building of a form from metadata instead of requiring annotations.
     * Annotations are still allowed through the ElementAnnotationsListener.
     *
     * {@inheritDoc}
     */
    public function getFormSpecification($entity)
    {
        $formSpec    = parent::getFormSpecification($entity);
        $metadata    = $this->objectManager->getClassMetadata(is_object($entity) ? get_class($entity) : $entity);
        $inputFilter = $formSpec['input_filter'];

        $formElements = [
            Element\ObjectSelect::class,
            Element\ObjectMultiCheckbox::class,
            Element\ObjectRadio::class,
        ];

        foreach ($formSpec['elements'] as $key => $elementSpec) {
            $name          = $elementSpec['spec']['name'] ?? null;
            $isFormElement = (isset($elementSpec['spec']['type']) &&
                              in_array($elementSpec['spec']['type'], $formElements));

            if (! $name) {
                continue;
            }

            if (! isset($inputFilter[$name])) {
                $inputFilter[$name] = new ArrayObject();
            }

            $params = [
                'metadata'    => $metadata,
                'name'        => $name,
                'elementSpec' => $elementSpec,
                'inputSpec'   => $inputFilter[$name],
            ];

            if ($this->checkForExcludeElementFromMetadata($metadata, $name)) {
                $elementSpec = $formSpec['elements'];
                unset($elementSpec[$key]);
                $formSpec['elements'] = $elementSpec;

                if (isset($inputFilter[$name])) {
                    unset($inputFilter[$name]);
                }

                $formSpec['input_filter'] = $inputFilter;
                continue;
            }

            if ($metadata->hasField($name) || (! $metadata->hasAssociation($name) && $isFormElement)) {
                $this->getEventManager()->trigger(self::EVENT_CONFIGURE_FIELD, $this, $params);
            } elseif ($metadata->hasAssociation($name)) {
                $this->getEventManager()->trigger(self::EVENT_CONFIGURE_ASSOCIATION, $this, $params);
            }
        }

        $formSpec['options'] = ['prefer_form_input_filter' => true];

        return $formSpec;
    }

    protected function checkForExcludeElementFromMetadata(ClassMetadata $metadata, string $name): bool
    {
        $params = ['metadata' => $metadata, 'name' => $name];
        $result = false;

        if ($metadata->hasField($name)) {
            $result = $this->getEventManager()->trigger(self::EVENT_EXCLUDE_FIELD, $this, $params);
        } elseif ($metadata->hasAssociation($name)) {
            $result = $this->getEventManager()->trigger(self::EVENT_EXCLUDE_ASSOCIATION, $this, $params);
        }

        if ($result) {
            $result = (bool) $result->last();
        }

        return $result;
    }
}

class_exists(ClassMetadata::class);
