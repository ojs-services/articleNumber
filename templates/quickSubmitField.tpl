{**
 * plugins/generic/articleNumber/templates/quickSubmitField.tpl
 *
 * Article Number Plugin for OJS — QuickSubmit metadata-section field.
 *
 * Injected into the QuickSubmit form's submission-metadata section via the
 * Templates::Submission::SubmissionMetadataForm::AdditionalMetadata hook
 * (scoped to QuickSubmit). The input name "workNumber" matches the var the
 * form reads via the quicksubmitform::readuservars hook.
 *
 * @uses $articleNumberQsValue string Current Article Number value (may be empty).
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *}
{fbvFormArea id="articleNumberQuickSubmitArea"}
	{fbvFormSection title="plugins.generic.articleNumber.field.label" for="workNumber"}
		{fbvElement
			type="text"
			id="workNumber"
			name="workNumber"
			value=$articleNumberQsValue
			maxlength="255"
		}
	{/fbvFormSection}
{/fbvFormArea}
