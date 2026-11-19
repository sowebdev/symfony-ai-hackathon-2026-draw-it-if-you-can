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
 * @extends AbstractType<ModelsStep>
 */
final class ModelsStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $typed = ['required' => false, 'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'autocapitalize' => 'off']];
        foreach (['drawer', 'guesser'] as $role) {
            // A list only when there is something to choose from: otherwise the text field is alone.
            if ([] !== $options[$role.'_choices']) {
                // Radio buttons: the page draws them as cards, or lists them under "all the models".
                $builder->add($role.'Choice', ChoiceType::class, [
                    'choices' => $options[$role.'_choices'],
                    'expanded' => true,
                    'choice_translation_domain' => false,
                    'placeholder' => false,
                    'required' => false,
                    'label' => false,
                    'invalid_message' => 'error.model_invalid',
                ]);
            }
            $builder->add($role.'Name', TextType::class, $typed + ['label' => 'models.type_name']);
            if ('drawer' === $role) {
                $builder->add('otherGuesser', CheckboxType::class, ['required' => false, 'label' => 'models.other_guesser']);
            }
        }
        if ($options['reasoning_switch']) {
            $builder->add('reasoningOff', CheckboxType::class, ['required' => false, 'label' => 'models.reasoning_off']);
        }
        $builder->add('check', SubmitType::class, ['label' => 'action.check_models']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ModelsStep::class,
            // Model name => model name, as the list gives them.
            'guesser_choices' => [],
            'drawer_choices' => [],
            'reasoning_switch' => false,
        ]);
        $resolver->setAllowedTypes('guesser_choices', 'array');
        $resolver->setAllowedTypes('drawer_choices', 'array');
        $resolver->setAllowedTypes('reasoning_switch', 'bool');
    }
}
