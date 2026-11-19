<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ModelStep>
 */
final class ModelStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // A list only when there is something to choose from: otherwise the text field is alone.
        if ([] !== $options['choices']) {
            // Radio buttons: the page draws them as cards, or lists them under "all the models".
            $builder->add('choice', ChoiceType::class, [
                'choices' => $options['choices'],
                'expanded' => true,
                'choice_translation_domain' => false,
                'placeholder' => false,
                'required' => false,
                'label' => false,
                'invalid_message' => 'error.model_invalid',
            ]);
        }
        $builder->add('name', TextType::class, [
            'required' => false,
            'label' => 'models.type_name',
            'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'autocapitalize' => 'off'],
        ]);
        if ($options['reasoning_switch']) {
            $builder->add('reasoningOff', CheckboxType::class, ['required' => false, 'label' => 'models.reasoning_off']);
        }
        $builder->add('check', SubmitType::class, ['label' => 'action.check_and_play']);
        if (null !== $options['another']) {
            // Saves this model and goes on to the question of the other role.
            $builder->add('another', SubmitType::class, ['label' => $options['another']]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ModelStep::class,
            // Model name => model name, as the list gives them.
            'choices' => [],
            'reasoning_switch' => false,
            // The label of the button that goes on to another role, or null for none.
            'another' => null,
        ]);
        $resolver->setAllowedTypes('choices', 'array');
        $resolver->setAllowedTypes('reasoning_switch', 'bool');
        $resolver->setAllowedTypes('another', ['string', 'null']);
    }
}
