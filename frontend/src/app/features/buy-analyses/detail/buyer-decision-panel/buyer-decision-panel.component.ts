import { Component, effect, inject, input, output, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { finalize } from 'rxjs';

import { apiErrorMessage } from '../../../../core/api/api-error';
import {
  Analysis,
  BuyerDecisionEvent,
  BuyerDecisionState,
  BuyerDecisionSubmission,
  DealScore,
} from '../../../../core/analysis.models';
import { AnalysisService } from '../../../../core/analysis.service';
import { AnalysisCodeLabelService } from '../../../../core/i18n/analysis-code-label.service';
import { I18nService } from '../../../../core/i18n/i18n.service';
import { TranslatePipe } from '../../../../core/i18n/translate.pipe';

@Component({
  selector: 'app-buyer-decision-panel',
  imports: [ReactiveFormsModule, TranslatePipe],
  templateUrl: './buyer-decision-panel.component.html',
  styleUrl: './buyer-decision-panel.component.scss',
})
export class BuyerDecisionPanelComponent {
  private readonly analyses = inject(AnalysisService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly i18n = inject(I18nService);
  private readonly codeLabels = inject(AnalysisCodeLabelService);
  private contextKey: string | null = null;
  private pendingFingerprint: string | null = null;
  private pendingIdempotencyKey: string | null = null;

  readonly analysisId = input.required<string>();
  readonly dealScore = input.required<DealScore>();
  readonly currentDecision = input<BuyerDecisionEvent | null>(null);
  readonly allowedTransitions = input<readonly BuyerDecisionState[]>([]);
  readonly history = input<readonly BuyerDecisionEvent[]>([]);
  readonly historyCount = input(0);
  readonly canManage = input(false);
  readonly analysisUpdated = output<Analysis>();

  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly success = signal<string | null>(null);
  protected readonly form = this.formBuilder.nonNullable.group({
    next_state: ['', [Validators.required]],
    reason_code: [
      '',
      [
        Validators.maxLength(64),
        Validators.pattern(/^[a-z0-9]+(?:_[a-z0-9]+)*$/),
      ],
    ],
    note: ['', [Validators.maxLength(1000)]],
  });

  constructor() {
    effect(() => {
      const key = `${this.dealScore().id}:${this.currentDecision()?.id ?? 'none'}`;

      if (key === this.contextKey) {
        return;
      }

      this.contextKey = key;
      this.pendingFingerprint = null;
      this.pendingIdempotencyKey = null;
      this.error.set(null);
      const selected = this.form.controls.next_state.value;

      if (
        selected !== '' &&
        !this.allowedTransitions().includes(selected as BuyerDecisionState)
      ) {
        this.form.controls.next_state.setValue('');
      }
    });
  }

  protected submit(): void {
    if (!this.canManage() || this.allowedTransitions().length === 0) {
      return;
    }

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.error.set(this.i18n.translate('buyerDecision.formError'));
      return;
    }

    const values = this.form.getRawValue();
    const nextState = this.allowedTransitions().find(
      (state) => state === values.next_state,
    );

    if (nextState === undefined) {
      this.error.set(this.i18n.translate('buyerDecision.staleState'));
      return;
    }

    const command = {
      deal_score_id: this.dealScore().id,
      expected_current_event_id: this.currentDecision()?.id ?? null,
      next_state: nextState,
      reason_code: values.reason_code.trim() || null,
      note: values.note.trim() || null,
    };
    const fingerprint = JSON.stringify(command);

    if (
      fingerprint !== this.pendingFingerprint ||
      this.pendingIdempotencyKey === null
    ) {
      this.pendingFingerprint = fingerprint;
      this.pendingIdempotencyKey = globalThis.crypto.randomUUID();
    }

    const submission: BuyerDecisionSubmission = {
      ...command,
      idempotency_key: this.pendingIdempotencyKey,
    };

    this.saving.set(true);
    this.error.set(null);
    this.success.set(null);
    this.analyses
      .recordBuyerDecision(this.analysisId(), submission)
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (response) => {
          this.pendingFingerprint = null;
          this.pendingIdempotencyKey = null;
          this.form.reset({
            next_state: '',
            reason_code: '',
            note: '',
          });
          this.success.set(
            response.created
              ? this.i18n.translate('buyerDecision.created')
              : this.i18n.translate('buyerDecision.duplicate'),
          );
          this.analysisUpdated.emit(response.analysis);
        },
        error: (error: unknown) => {
          this.error.set(
            apiErrorMessage(
              error,
              this.i18n.translate('buyerDecision.saveError'),
            ),
          );
        },
      });
  }

  protected label(value: string): string {
    return this.codeLabels.label(value);
  }

  protected date(value: string): string {
    return this.i18n.formatDate(value, {
      dateStyle: 'medium',
      timeStyle: 'short',
    });
  }
}
