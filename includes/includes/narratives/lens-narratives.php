<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ICON Catalyst — Lens narrative templates (Enhanced + higher variety, still deterministic)
 *
 * Goal:
 * - Ensure each trait/competency narrative does NOT “read the same” across reports.
 * - Keep deterministic output (no external AI) and ALWAYS return EXACTLY two paragraphs.
 * - Use richer context signals if present: description keywords, weakest/strongest lens, lens gap,
 *   self/others gap, consistency (sd), sample size (n), and optional role/level.
 *
 * Usage:
 *   $html = icon_psy_lens_narrative_html( $ctx );
 *
 * Required ctx keys (backwards compatible; missing keys handled):
 * - lens_code (string)
 * - competency (string)
 * - description (string)
 * - overall (float 0..7)
 * - avg_q1, avg_q2, avg_q3 (float 0..7)
 * - self (float|null)
 * - others (float|null)
 *
 * Optional ctx keys (highly recommended):
 * - n (int)                // sample size behind the competency averages
 * - sd (float|null)        // standard deviation of overall values
 * - role (string)          // participant role/job title
 * - level (string)         // e.g., "Executive", "Manager", "IC"
 * - audience (string)      // e.g., "Team", "Stakeholders", "Clients"
 *
 * Optional ctx keys (presentation):
 * - descriptor (string)    // short “definition” sentence(s) shown above Insight
 * - show_descriptor (bool) // if false, never show descriptor block
 *
 * Notes:
 * - Paragraph 1 = observed impact (band + pattern + signals + calibration + consequences)
 * - Paragraph 2 = how to strengthen (weakest lens + band + competency type cues + practical play)
 */

if ( ! function_exists( 'icon_psy_lens_score_band' ) ) {
	function icon_psy_lens_score_band( $overall ) {
		$overall = (float) $overall;
		if ( $overall >= 5.5 ) return 'high';
		if ( $overall >= 4.5 ) return 'mid';
		return 'low';
	}
}

if ( ! function_exists( 'icon_psy_lens_gap_tag' ) ) {
	function icon_psy_lens_gap_tag( $self, $others ) {
		if ( $self === null || $others === null ) return 'nogap';
		$gap = (float) $others - (float) $self;
		if ( abs( $gap ) < 0.4 ) return 'aligned';
		if ( $gap > 0.4 ) return 'others_higher';
		return 'self_higher';
	}
}

if ( ! function_exists( 'icon_psy_lens_sentence_from_band' ) ) {
	function icon_psy_lens_sentence_from_band( $band, $high, $mid, $low ) {
		if ( $band === 'high' ) return $high;
		if ( $band === 'mid' )  return $mid;
		return $low;
	}
}

/** Stable picker so variation is consistent per key. */
if ( ! function_exists( 'icon_psy_lens_pick' ) ) {
	function icon_psy_lens_pick( $key, $variants ) {
		$variants = (array) $variants;
		if ( empty( $variants ) ) return '';
		$h = function_exists( 'crc32' ) ? crc32( (string) $key ) : strlen( (string) $key );
		$idx = (int) ( abs( (int) $h ) % count( $variants ) );
		return (string) $variants[ $idx ];
	}
}

/** Basic sanitation for narrative text */
if ( ! function_exists( 'icon_psy_lens_clean' ) ) {
	function icon_psy_lens_clean( $text ) {
		$text = is_string( $text ) ? trim( $text ) : '';
		if ( $text === '' ) return '';
		$text = str_replace( array( "—", "–" ), '-', $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}
}

/** Short trim helper for presentation blocks */
if ( ! function_exists( 'icon_psy_lens_trim' ) ) {
	function icon_psy_lens_trim( $text, $max = 220 ) {
		$text = is_string( $text ) ? trim( $text ) : '';
		if ( $text === '' ) return '';
		$text = preg_replace( '/\s+/', ' ', $text );

		if ( function_exists( 'mb_strlen' ) ) {
			if ( mb_strlen( $text ) <= $max ) return $text;
			return rtrim( mb_substr( $text, 0, max( 0, $max - 1 ) ) ) . '...';
		}
		if ( strlen( $text ) <= $max ) return $text;
		return rtrim( substr( $text, 0, max( 0, $max - 1 ) ) ) . '...';
	}
}

/** Identify weakest/strongest lens + pattern label */
if ( ! function_exists( 'icon_psy_lens_pattern' ) ) {
	function icon_psy_lens_pattern( $avg_q1, $avg_q2, $avg_q3 ) {
		$q1 = (float) $avg_q1; $q2 = (float) $avg_q2; $q3 = (float) $avg_q3;

		$min = min( $q1, $q2, $q3 );
		$max = max( $q1, $q2, $q3 );

		$weak = 'Everyday';
		if ( $min === $q2 ) $weak = 'Pressure';
		elseif ( $min === $q3 ) $weak = 'Role-model';

		$strong = 'Everyday';
		if ( $max === $q2 ) $strong = 'Pressure';
		elseif ( $max === $q3 ) $strong = 'Role-model';

		$gap = max( 0.0, (float) ( $max - $min ) );

		$shape = 'consistent';
		if ( $gap >= 0.8 ) {
			$shape = strtolower( $weak ) . '_dip';
		} elseif ( $gap >= 0.45 ) {
			$shape = 'some_variation';
		}

		return array(
			'weak_lens'   => $weak,
			'strong_lens' => $strong,
			'gap'         => $gap,
			'shape'       => $shape,
		);
	}
}

/** Describe self/others calibration */
if ( ! function_exists( 'icon_psy_lens_gap_phrase' ) ) {
	function icon_psy_lens_gap_phrase( $gap_tag ) {
		if ( $gap_tag === 'others_higher' ) return 'Others may be seeing more of this than you currently give yourself credit for.';
		if ( $gap_tag === 'self_higher' )   return 'You may feel this is landing, while others still need it to be more visible or consistent.';
		if ( $gap_tag === 'aligned' )       return 'Your self-view is broadly aligned with how others experience you.';
		return '';
	}
}

/** Add a confidence/early-signal phrase using n and sd if available */
if ( ! function_exists( 'icon_psy_lens_signal_phrase' ) ) {
	function icon_psy_lens_signal_phrase( $ctx, $key_seed ) {

		$n  = isset( $ctx['n'] ) ? (int) $ctx['n'] : 0;
		$sd = isset( $ctx['sd'] ) ? $ctx['sd'] : null;
		$sd = ( $sd === null || $sd === '' ) ? null : (float) $sd;

		// If no n provided, say nothing (avoid noise)
		if ( $n <= 0 && $sd === null ) return '';

		// Early signal by n
		if ( $n > 0 && $n < 3 ) {
			return icon_psy_lens_pick(
				'signal|nlt3|' . $key_seed,
				array(
					'This is an early signal based on a small number of responses, so validate with examples.',
					'Treat this as an early signal. A short example-based check-in will confirm what is really happening.',
					'Response volume is still low here, so use this as a starting point rather than a final verdict.'
				)
			);
		}

		// Emerging
		if ( $n >= 3 && $n < 5 ) {
			return icon_psy_lens_pick(
				'signal|nlt5|' . $key_seed,
				array(
					'This looks like an emerging pattern. Reinforce what works and test improvements in real work moments.',
					'This is becoming a pattern. The fastest progress comes from one agreed behaviour repeated consistently.',
					'Signals are starting to stabilise. Use a small behaviour experiment to tighten consistency.'
				)
			);
		}

		// If sd known, add consistency note
		if ( $sd !== null && $n >= 5 ) {
			if ( $sd < 0.60 ) {
				return icon_psy_lens_pick(
					'signal|sdlow|' . $key_seed,
					array(
						'Views are relatively aligned, which usually supports trust and predictability.',
						'Scores are fairly consistent across raters, which strengthens confidence in the signal.',
						'Rater views look aligned, suggesting a stable pattern rather than isolated incidents.'
					)
				);
			}
			if ( $sd < 1.00 ) {
				return icon_psy_lens_pick(
					'signal|sdmid|' . $key_seed,
					array(
						'There is some variation in experience, so agree what “good” looks like in practical terms.',
						'Views vary a bit. A shared standard will reduce mixed messages and speed up execution.',
						'This is mostly consistent but not universal. Tighten the team habit and re-check in two weeks.'
					)
				);
			}
			return icon_psy_lens_pick(
				'signal|sdhigh|' . $key_seed,
				array(
					'Views vary widely, suggesting different experiences by role or situation - use examples to pinpoint the cause.',
					'Rater views are split. Use concrete examples to identify when it lands and when it drops.',
					'This appears uneven across contexts. Target the situations where the behaviour matters most.'
				)
			);
		}

		return '';
	}
}

/** Explain pattern by situation (consistent / variation / dip) */
if ( ! function_exists( 'icon_psy_lens_shape_phrase' ) ) {
	function icon_psy_lens_shape_phrase( $shape, $weak, $gap ) {
		$gap = (float) $gap;

		if ( $shape === 'consistent' ) {
			return icon_psy_lens_pick(
				'shape|consistent|' . $weak,
				array(
					'The pattern looks fairly consistent across everyday work, pressure moments, and role-modelling.',
					'Scores suggest a relatively even experience across situations, which helps predictability.',
					'There is no major swing by situation, which usually supports trust and coordination.',
					'The experience stays broadly stable across contexts, which tends to reduce rework and escalation.'
				)
			);
		}

		if ( $shape === 'some_variation' ) {
			return icon_psy_lens_pick(
				'shape|variation|' . $weak,
				array(
					'There is some variation by situation, which is common when priorities shift or pace increases.',
					'Scores move a little between lenses, suggesting this shows up differently depending on context.',
					'The experience changes modestly by situation, so small stabilising habits will help.',
					'It lands in some contexts more than others, so consistency will improve with a simple shared routine.'
				)
			);
		}

		// dip
		return icon_psy_lens_pick(
			'shape|dip|' . $weak,
			array(
				"The biggest dip appears in {$weak}, suggesting the behaviour is harder to sustain in that situation (gap " . number_format( $gap, 1 ) . ").",
				"Results shift most in {$weak}. This is the context to stabilise first (gap " . number_format( $gap, 1 ) . ").",
				"The pattern suggests {$weak} is the toughest test of consistency here (gap " . number_format( $gap, 1 ) . ").",
				"{$weak} looks like the stress test for this behaviour. Stabilising it there will lift overall impact (gap " . number_format( $gap, 1 ) . ")."
			)
		);
	}
}

/**
 * Detect a simple “competency type” from the competency name/description.
 * This gives you varied language AND varied action guidance across traits.
 */
if ( ! function_exists( 'icon_psy_lens_competency_type' ) ) {
	function icon_psy_lens_competency_type( $competency, $description ) {

		$text = strtolower( trim( (string) $competency . ' ' . (string) $description ) );
		$text = preg_replace( '/[^a-z0-9\s]/', ' ', $text );
		$text = preg_replace( '/\s+/', ' ', $text );

		$rules = array(
			'communication' => array( 'communicat', 'clarity', 'message', 'listen', 'stakeholder', 'influence', 'present', 'story', 'brief', 'alignment' ),
			'decision'      => array( 'decision', 'judgement', 'judgment', 'risk', 'trade-off', 'priorit', 'problem', 'analysis', 'option' ),
			'people'        => array( 'coach', 'develop', 'empower', 'delegate', 'feedback', 'talent', 'team', 'people', 'culture' ),
			'execution'     => array( 'deliver', 'execution', 'accountab', 'ownership', 'deadline', 'plan', 'operate', 'process' ),
			'strategy'      => array( 'strategy', 'vision', 'horizon', 'future', 'direction', 'roadmap', 'transform', 'change' ),
			'culture'       => array( 'values', 'integrity', 'ethic', 'inclusion', 'divers', 'respect', 'trust', 'psychological' ),
			'resilience'    => array( 'resilien', 'adapt', 'pressure', 'stress', 'setback', 'calm', 'compos' ),
			'collaboration' => array( 'collabor', 'partner', 'conflict', 'involve', 'together', 'cross', 'matrix', 'alignment' ),
		);

		$scores = array();
		foreach ( $rules as $type => $needles ) {
			$scores[$type] = 0;
			foreach ( $needles as $n ) {
				if ( strpos( $text, $n ) !== false ) $scores[$type] += 1;
			}
		}

		arsort( $scores );
		$best = key( $scores );

		// If nothing matched, return a generic bucket
		if ( empty( $best ) || (int) $scores[$best] <= 0 ) return 'general';
		return $best;
	}
}

/** Extra “overuse” warning for high-band scores so highs don’t all read like each other */
if ( ! function_exists( 'icon_psy_lens_overuse_note' ) ) {
	function icon_psy_lens_overuse_note( $band, $type, $key_seed ) {
		if ( $band !== 'high' ) return '';

		$map = array(
			'communication' => array(
				'At its best this is crisp. The only watch-out is over-communicating detail when a simple headline would do.',
				'This is a strength. The watch-out is speed: clarity can tip into bluntness if you skip the human check.',
				'Strong communication can become “broadcast” if curiosity drops. Keep one question in the mix.'
			),
			'decision' => array(
				'This is a strength. The watch-out is moving so fast that others lose the “why” and don’t own the decision.',
				'Good judgement can still feel heavy if you carry it alone. Keep decision rights and criteria visible.',
				'When judgement is strong, people may defer too much. Protect empowerment by delegating decision space.'
			),
			'people' => array(
				'This is a strength. The watch-out is rescuing people rather than letting them grow through ownership.',
				'Strong development can become over-coaching. Keep it light: one question, one challenge, one check-in.',
				'When you support others well, be careful not to lower standards. Pair care with clear expectations.'
			),
			'execution' => array(
				'This is a strength. The watch-out is pushing pace so hard that reflection and learning get squeezed out.',
				'Strong execution can tip into micromanagement if you solve too much. Keep ownership with the right people.',
				'Delivery focus works best with breathing space. Protect one review loop to prevent silent drift.'
			),
			'strategy' => array(
				'This is a strength. The watch-out is staying in the future while people still need a concrete next step.',
				'Strategic clarity is powerful. The watch-out is abstraction: keep one practical example attached.',
				'Vision lands best when repeated. The watch-out is changing language too often, which resets alignment.'
			),
			'culture' => array(
				'This is a strength. The watch-out is being so principled that flexibility disappears in messy situations.',
				'Strong values build trust. The watch-out is assuming others share the same standards without stating them.',
				'Integrity is a differentiator. The watch-out is avoiding hard calls - standards still need enforcement.'
			),
			'resilience' => array(
				'This is a strength. The watch-out is looking “fine” while the team silently burns out. Check load openly.',
				'Calm under pressure is valuable. The watch-out is emotional distance - keep connection as well as composure.',
				'Resilience is strong. The watch-out is normalising chaos. Stabilise routines so pressure stays manageable.'
			),
			'collaboration' => array(
				'This is a strength. The watch-out is involving too many people in decisions that need speed.',
				'Collaboration works best with clear decision rules. The watch-out is consensus drift.',
				'Strong inclusion helps quality. The watch-out is delaying decisions - keep ownership and deadlines explicit.'
			),
			'general' => array(
				'This is a strength. The watch-out is relying on it so heavily that other capabilities get less attention.',
				'Strong performance here is valuable. Keep it sustainable by protecting rhythm and recovery.',
				'This lands well. The watch-out is assuming it is “done” - consistency is maintained through repetition.'
			),
		);

		$set = isset( $map[$type] ) ? $map[$type] : $map['general'];
		return icon_psy_lens_pick( 'overuse|' . $type . '|' . $key_seed, $set );
	}
}

/**
 * Action guidance tailored to weakest lens + competency type (this is where you get BIG variety).
 * Still generic enough to work across competencies, but now “shaped” by type.
 */
if ( ! function_exists( 'icon_psy_lens_strengthen_play' ) ) {
	function icon_psy_lens_strengthen_play( $weak_lens, $band, $competency, $type = 'general' ) {
		$competency = (string) $competency;

		$everyday = array(
			"Make {$competency} visible in routine moments: open meetings with the headline, confirm owner and next step, and close with a 10-second recap.",
			"Build a simple ‘standard move’ into the weekly rhythm (one prompt, one decision, one next action). Repetition is what makes it stick.",
			"Reduce ambiguity by naming what matters most in the first minute, then checking understanding before moving on."
		);

		$pressure = array(
			"Under pressure, use a short reset: priorities, roles, risks. Keep it to 90 seconds and repeat it whenever pace rises.",
			"Stabilise decision-making in busy periods with one rule: one owner, one next action, one deadline. Make it non-negotiable.",
			"When tension rises, slow the moment that matters: clarify options, make the call, and communicate it in one clean sentence."
		);

		$rolemodel = array(
			"Strengthen role-modelling by making ‘what good looks like’ explicit, demonstrating it in real time, and recognising it when you see it in others.",
			"Narrate the ‘why’ behind choices once per day. People copy what they understand, not just what they’re told.",
			"Pick one standard you will protect publicly. Follow through consistently, especially when it is inconvenient."
		);

		$set = $everyday;
		if ( $weak_lens === 'Pressure' ) $set = $pressure;
		elseif ( $weak_lens === 'Role-model' ) $set = $rolemodel;

		$type_addons = array(
			'communication' => array(
				"Use a simple message frame: headline, why it matters, decision/ask, next action, deadline.",
				"In key conversations, reflect back what you heard before you respond. It increases clarity and trust at the same time.",
				"Make ambiguity visible by asking: “What is the decision we need?” Then close the loop with one sentence."
			),
			'decision' => array(
				"Name the criteria before you choose: risk, speed, impact, cost. It reduces rework and second-guessing.",
				"Make trade-offs explicit. People follow decisions faster when they understand what you protected and what you sacrificed.",
				"Add a “decision owner” and “review point” to avoid drift and repeated revisiting."
			),
			'people' => array(
				"Move from helping to coaching: one question that makes them think, then let them own the next step.",
				"Give clear decision space: what they own, what they can decide alone, and when to escalate.",
				"Use micro-feedback in the moment: one behaviour, one impact, one next move."
			),
			'execution' => array(
				"Turn intent into cadence: weekly priorities, owners, visible blockers, and a short end-of-week review.",
				"Reduce friction by tightening handovers: one owner, one next action, one deadline, one check.",
				"Protect quality under pace with a short risk scan before committing to a timeline."
			),
			'strategy' => array(
				"Connect today’s priority to a longer outcome: what we are building, what success looks like, and what we will not do.",
				"Repeat a stable “north star” phrase so people can make good independent decisions without re-checking.",
				"Translate strategy into one next milestone with a clear owner and a visible progress signal."
			),
			'culture' => array(
				"Make standards explicit: what good looks like, what is not acceptable, and what happens when the line is crossed.",
				"Reinforce the behaviour you want publicly. Culture changes when people see what gets recognised and repeated.",
				"Use fairness checks: apply the same standard across people and situations, especially when it is uncomfortable."
			),
			'resilience' => array(
				"Make your reset visible: acknowledge pressure, name what is in control, choose the next smallest step.",
				"Stabilise routines when the pace rises: priorities, roles, risks - short and repeatable.",
				"Balance calm with connection: check load and capacity explicitly so resilience stays sustainable."
			),
			'collaboration' => array(
				"Clarify decision method: consult, decide, or co-create. People collaborate better when decision rules are explicit.",
				"Make roles visible in cross-team work: who owns, who supports, who must be consulted.",
				"Surface friction early with one shared goal, then agree a concrete next step rather than debating endlessly."
			),
			'general' => array(
				"Choose one visible behaviour to repeat for two weeks, then review the impact using real examples.",
				"Turn the intent into a routine so it shows up even when workload increases.",
				"Make success observable: define what you should see/hear when this is working well."
			),
		);

		$addon = isset( $type_addons[$type] ) ? $type_addons[$type] : $type_addons['general'];

		if ( $band === 'high' ) {
			$scale = array(
				"To scale this strength, teach the ‘how’ to others: name the standard, demonstrate it once, then let someone else run it and debrief the outcome.",
				"Protect the strength by applying it deliberately in a tougher context (higher stakes, conflict, time pressure), then capture what made it work.",
				"Turn this into a team advantage by agreeing a shared standard and reinforcing it publicly when it shows up."
			);

			$first  = icon_psy_lens_pick( 'play|scale|' . $weak_lens . '|' . $type . '|' . $competency, $scale );
			$second = icon_psy_lens_pick( 'play|addon|' . $weak_lens . '|high|' . $type . '|' . $competency, $addon );
			return icon_psy_lens_clean( $first . ' ' . $second );
		}

		$first  = icon_psy_lens_pick( 'play|' . $weak_lens . '|' . $band . '|' . $competency, $set );
		$second = icon_psy_lens_pick( 'play|addon|' . $weak_lens . '|' . $band . '|' . $type . '|' . $competency, $addon );
		return icon_psy_lens_clean( $first . ' ' . $second );
	}
}

/** Add “impact lens” sentence so different competencies have different consequence language */
if ( ! function_exists( 'icon_psy_lens_impact_sentence' ) ) {
	function icon_psy_lens_impact_sentence( $band, $type, $key_seed ) {

		$impact = array(
			'communication' => array(
				'When this is strong, it reduces churn because people act with clearer shared understanding.',
				'This tends to lift speed and alignment because fewer messages need repeating or re-clarifying.',
				'It improves coordination because decisions, owners, and deadlines become more explicit.'
			),
			'decision' => array(
				'When this is strong, the team spends less time re-litigating choices and more time executing.',
				'This improves pace and confidence because trade-offs are clearer and decisions stick.',
				'It reduces escalation because people understand criteria and decision ownership.'
			),
			'people' => array(
				'When this is strong, capability increases and the team relies less on escalation or rescue.',
				'It builds confidence and initiative because people know what they own and how to grow.',
				'This improves retention and energy because development feels intentional rather than accidental.'
			),
			'execution' => array(
				'When this is strong, delivery becomes more predictable and blockers surface earlier.',
				'It improves reliability because ownership and follow-through are clearer.',
				'This reduces rework because priorities and standards stay visible under pace.'
			),
			'strategy' => array(
				'When this is strong, effort aligns to outcomes rather than activity, which improves impact.',
				'It increases coherence because people can see how today links to the bigger direction.',
				'This improves independent decision-making because the “north star” is clearer.'
			),
			'culture' => array(
				'When this is strong, trust rises and people speak up earlier about risks and concerns.',
				'It improves fairness and safety because standards are consistent and visible.',
				'This reduces politics because expectations and boundaries are clearer.'
			),
			'resilience' => array(
				'When this is strong, the team stays productive through volatility instead of becoming reactive.',
				'It improves steadiness because pressure is managed with visible routines and calm.',
				'This reduces burnout risk because recovery and load become part of the operating rhythm.'
			),
			'collaboration' => array(
				'When this is strong, cross-team work speeds up because decision rules and roles are clearer.',
				'It improves outcomes because diverse input is used without slowing decisions unnecessarily.',
				'This reduces friction because issues are surfaced earlier and resolved with shared ownership.'
			),
			'general' => array(
				'When this is strong, execution is smoother because behaviours are predictable and repeatable.',
				'It improves confidence because people know what “good” looks like and can meet it.',
				'This reduces noise because fewer things need re-explaining or re-deciding.'
			),
		);

		$set = isset( $impact[$type] ) ? $impact[$type] : $impact['general'];

		if ( $band === 'low' ) {
			$set = array(
				'When this is inconsistent, the team pays in rework, churn, and avoidable escalation.',
				'When this is weak, execution slows because people do not share the same understanding of what matters.',
				'If this is not landing, decisions and handovers tend to blur, which increases friction under load.'
			);
		} elseif ( $band === 'mid' ) {
			$set = array_merge( $set, array(
				'The opportunity now is consistency - turning a good baseline into a repeatable habit.',
				'The gap is usually in repeatability under pressure rather than capability in principle.',
				'Small routine changes tend to lift this faster than big one-off interventions.'
			) );
		}

		return icon_psy_lens_pick( 'impact|' . $band . '|' . $type . '|' . $key_seed, $set );
	}
}

/**
 * MAIN TEMPLATES
 * Each returns EXACTLY two paragraphs (plain text).
 */
if ( ! function_exists( 'icon_psy_lens_templates' ) ) {
	function icon_psy_lens_templates() {

		$build = function( $ctx, $code, $theme_key, $default_comp_label ) {

			$overall = isset( $ctx['overall'] ) ? (float) $ctx['overall'] : 0.0;
			$avg_q1  = isset( $ctx['avg_q1'] ) ? (float) $ctx['avg_q1'] : 0.0;
			$avg_q2  = isset( $ctx['avg_q2'] ) ? (float) $ctx['avg_q2'] : 0.0;
			$avg_q3  = isset( $ctx['avg_q3'] ) ? (float) $ctx['avg_q3'] : 0.0;

			$comp = isset( $ctx['competency'] ) ? (string) $ctx['competency'] : (string) $default_comp_label;
			$desc = isset( $ctx['description'] ) ? (string) $ctx['description'] : '';

			$band    = icon_psy_lens_score_band( $overall );
			$gap_tag = icon_psy_lens_gap_tag(
				array_key_exists( 'self', $ctx ) ? $ctx['self'] : null,
				array_key_exists( 'others', $ctx ) ? $ctx['others'] : null
			);
			$pat     = icon_psy_lens_pattern( $avg_q1, $avg_q2, $avg_q3 );

			$type = icon_psy_lens_competency_type( $comp, $desc );

			$key_seed = $code . '|' . $comp;

			$high = icon_psy_lens_pick( $theme_key . '|high|' . $key_seed, array(
				"Results indicate this is landing as a clear strength. Others experience {$comp} as reliable and helpful to performance and coordination.",
				"{$comp} comes through strongly. People are likely benefiting from predictable behaviours in this area, which improves pace and confidence.",
				"Scores suggest this is a standout capability. It is likely creating clarity, trust, or momentum depending on the situation."
			) );

			$mid = icon_psy_lens_pick( $theme_key . '|mid|' . $key_seed, array(
				"{$comp} appears solid in many situations, with headroom to make it more consistent and repeatable.",
				"Results suggest a stable baseline for {$comp}. The opportunity is turning “good” into a dependable team advantage.",
				"{$comp} is generally effective, but consistency by situation is the lever for the next improvement step."
			) );

			$low = icon_psy_lens_pick( $theme_key . '|low|' . $key_seed, array(
				"{$comp} is a clear development opportunity. Inconsistency here can create friction, rework, and slower execution under load.",
				"Results suggest {$comp} is not landing consistently yet. This can reduce confidence, alignment, and decision speed.",
				"{$comp} looks like a priority. Strengthening this area will likely improve coordination and reduce escalation."
			) );

			$p1 = icon_psy_lens_sentence_from_band( $band, $high, $mid, $low );

			$shape_phrase  = icon_psy_lens_shape_phrase( $pat['shape'], $pat['weak_lens'], $pat['gap'] );
			$gap_phrase    = icon_psy_lens_gap_phrase( $gap_tag );
			$signal_phrase = icon_psy_lens_signal_phrase( $ctx, $key_seed );
			$impact_phrase = icon_psy_lens_impact_sentence( $band, $type, $key_seed );
			$overuse_note  = icon_psy_lens_overuse_note( $band, $type, $key_seed );

			$adds = array_filter( array( $shape_phrase, $impact_phrase, $gap_phrase, $signal_phrase, $overuse_note ) );
			if ( ! empty( $adds ) ) {
				$p1 = icon_psy_lens_clean( $p1 . ' ' . implode( ' ', $adds ) );
			} else {
				$p1 = icon_psy_lens_clean( $p1 );
			}

			$p2 = icon_psy_lens_strengthen_play( $pat['weak_lens'], $band, $comp, $type );

			$close_map = array(
				'communication' => array(
					"In the next real meeting, lead with the headline, confirm the decision/ask, and close with owner + next step + deadline.",
					"Make the message sticky: one headline, one reason, one action. Then invite a 10-second summary back.",
					"To stabilise this, reduce thread-switching: park side issues and return to the single takeaway before closing."
				),
				'decision' => array(
					"To lock this in, name criteria before choosing, then close the loop with what changes and who owns the next step.",
					"Use the same decision frame repeatedly so people learn how you decide and can follow without rework.",
					"If you want faster execution, confirm the decision owner and the review point before the meeting ends."
				),
				'people' => array(
					"Make growth visible: agree decision space, success criteria, and one check-in. Then let them own the work.",
					"Build capability with a repeatable pattern: stretch, support, feedback, and a small reflection loop.",
					"To speed progress, coach in the moment: one behaviour, one impact, one next move."
				),
				'execution' => array(
					"Stabilise delivery with cadence: priorities, owners, blockers, and a short weekly review to prevent drift.",
					"To make this repeatable, tighten handovers and close loops in real time rather than later.",
					"Protect quality under pace by doing a quick risk scan before committing to timelines."
				),
				'strategy' => array(
					"To strengthen strategic pull, repeat the same north star language and link it to one practical milestone.",
					"Make trade-offs explicit: what you are protecting, what you are deprioritising, and what success looks like.",
					"To make this land, attach one real example so the strategy stays concrete, not abstract."
				),
				'culture' => array(
					"Culture changes through reinforcement: name the standard, model it, and recognise it when you see it.",
					"To build trust quickly, apply standards consistently and close loops when expectations are not met.",
					"Make ‘what good looks like’ explicit, especially in pressure moments when people watch leaders most."
				),
				'resilience' => array(
					"Under pressure, make the reset visible: priorities, roles, risks - short and repeatable.",
					"To keep resilience sustainable, check load and capacity explicitly rather than carrying it silently.",
					"Stabilise routines when volatility rises, so the team does not default to reactive firefighting."
				),
				'collaboration' => array(
					"Clarify how decisions will be made and who owns what. Collaboration speeds up when rules are explicit.",
					"Surface friction early, name the shared goal, then agree one concrete next step to convert talk into delivery.",
					"To avoid consensus drift, keep ownership, deadlines, and decision rights visible."
				),
				'general' => array(
					"Keep it small and observable: one behaviour, repeated for two weeks, then review with real examples.",
					"Stabilise this through rhythm: a short repeatable routine will beat a one-off effort.",
					"To make this stick, define what you should see/hear when it is working, then reinforce it in the moment."
				),
			);
			$close_set = isset( $close_map[$type] ) ? $close_map[$type] : $close_map['general'];
			$p2 = icon_psy_lens_clean( $p2 . ' ' . icon_psy_lens_pick( 'close|' . $type . '|' . $band . '|' . $key_seed, $close_set ) );

			return array( $p1, $p2 );
		};

		return array(

			'CLARITY' => function( $ctx ) use ( $build ) {
				return $build( $ctx, 'CLARITY', 'clarity', 'Effective Communication' );
			},

			'GROWTH' => function( $ctx ) use ( $build ) {
				return $build( $ctx, 'GROWTH', 'growth', 'People Development' );
			},

			'HORIZON' => function( $ctx ) use ( $build ) {
				return $build( $ctx, 'HORIZON', 'horizon', 'Strategic Leadership' );
			},

			'SELF_AWARENESS' => function( $ctx ) use ( $build ) {
				return $build( $ctx, 'SELF_AWARENESS', 'self_awareness', 'Emotional Self-Awareness' );
			},

			'EMPATHY' => function( $ctx ) use ( $build ) {
				return $build( $ctx, 'EMPATHY', 'empathy', 'Empathetic Connection' );
			},

			'VALUES' => function( $ctx ) use ( $build ) {
				return $build( $ctx, 'VALUES', 'values', 'Authentic Integrity' );
			},

			'VISION' => function( $ctx ) use ( $build ) {
				return $build( $ctx, 'VISION', 'vision', 'Inspirational Visioning' );
			},

			'RESILIENCE' => function( $ctx ) use ( $build ) {
				return $build( $ctx, 'RESILIENCE', 'resilience', 'Adaptive Resilience' );
			},

			'INFLUENCE' => function( $ctx ) use ( $build ) {
				return $build( $ctx, 'INFLUENCE', 'influence', 'Social Influence' );
			},

			'JUDGEMENT' => function( $ctx ) use ( $build ) {
				return $build( $ctx, 'JUDGEMENT', 'judgement', 'Judgement' );
			},

			'PRESENCE' => function( $ctx ) use ( $build ) {
				return $build( $ctx, 'PRESENCE', 'presence', 'Mindful Presence' );
			},

			'COLLABORATION' => function( $ctx ) use ( $build ) {
				return $build( $ctx, 'COLLABORATION', 'collaboration', 'Collaboration' );
			},
		);
	}
}

if ( ! function_exists( 'icon_psy_lens_narrative_text' ) ) {
	function icon_psy_lens_narrative_text( $ctx ) {

		$lens = isset( $ctx['lens_code'] ) ? strtoupper( trim( (string) $ctx['lens_code'] ) ) : 'CLARITY';
		$templates = icon_psy_lens_templates();

		$overall = isset( $ctx['overall'] ) ? (float) $ctx['overall'] : 0.0;
		$avg_q1  = isset( $ctx['avg_q1'] ) ? (float) $ctx['avg_q1'] : 0.0;
		$avg_q2  = isset( $ctx['avg_q2'] ) ? (float) $ctx['avg_q2'] : 0.0;
		$avg_q3  = isset( $ctx['avg_q3'] ) ? (float) $ctx['avg_q3'] : 0.0;

		$ctx = is_array( $ctx ) ? $ctx : array();
		if ( ! isset( $ctx['competency'] ) )   $ctx['competency'] = 'this competency';
		if ( ! isset( $ctx['description'] ) )  $ctx['description'] = '';
		if ( ! isset( $ctx['overall'] ) )      $ctx['overall'] = $overall;
		if ( ! isset( $ctx['avg_q1'] ) )       $ctx['avg_q1'] = $avg_q1;
		if ( ! isset( $ctx['avg_q2'] ) )       $ctx['avg_q2'] = $avg_q2;
		if ( ! isset( $ctx['avg_q3'] ) )       $ctx['avg_q3'] = $avg_q3;
		if ( ! array_key_exists( 'self', $ctx ) )   $ctx['self'] = null;
		if ( ! array_key_exists( 'others', $ctx ) ) $ctx['others'] = null;

		if ( isset( $templates[ $lens ] ) && is_callable( $templates[ $lens ] ) ) {
			$pair = call_user_func( $templates[ $lens ], $ctx );
			$p1 = isset( $pair[0] ) ? icon_psy_lens_clean( (string) $pair[0] ) : '';
			$p2 = isset( $pair[1] ) ? icon_psy_lens_clean( (string) $pair[1] ) : '';
		} else {
			$comp = (string) $ctx['competency'];
			$desc = (string) $ctx['description'];

			$band = icon_psy_lens_score_band( (float) $ctx['overall'] );
			$pat  = icon_psy_lens_pattern( (float)$ctx['avg_q1'], (float)$ctx['avg_q2'], (float)$ctx['avg_q3'] );
			$type = icon_psy_lens_competency_type( $comp, $desc );

			$key_seed = 'GEN|' . $lens . '|' . $comp;

			$p1 = icon_psy_lens_clean(
				"This competency reflects how consistently your behaviour is experienced in practice. " .
				icon_psy_lens_shape_phrase( $pat['shape'], $pat['weak_lens'], $pat['gap'] ) . " " .
				icon_psy_lens_impact_sentence( $band, $type, $key_seed ) . " " .
				icon_psy_lens_gap_phrase( icon_psy_lens_gap_tag( $ctx['self'], $ctx['others'] ) ) . " " .
				icon_psy_lens_signal_phrase( $ctx, $key_seed )
			);

			$p2 = icon_psy_lens_clean(
				icon_psy_lens_strengthen_play( $pat['weak_lens'], $band, $comp, $type ) .
				" " . icon_psy_lens_pick( 'gen|close|' . $type . '|' . $key_seed, array(
					"Keep it small, visible, and repeatable, then validate using one example-based conversation.",
					"Agree one behaviour experiment for two weeks, then review impact using real work moments.",
					"Choose one routine situation to practise the behaviour, then tighten it with quick feedback."
				) )
			);
		}

		if ( $p1 === '' ) $p1 = "This competency reflects how your behaviour is experienced by others in practice.";
		if ( $p2 === '' ) $p2 = "To build it further, focus on one small, repeatable behaviour change and track the impact over the next week.";

		return array( $p1, $p2 );
	}
}

/**
 * UPDATED OUTPUT:
 * - Adds a clean “Insight” label so it feels premium and clearly not the competency definition.
 * - Optionally shows a separate “Competency focus” descriptor block (from ctx['descriptor'] or ctx['description']).
 * - Keeps EXACTLY two narrative paragraphs.
 */
if ( ! function_exists( 'icon_psy_lens_narrative_html' ) ) {
	function icon_psy_lens_narrative_html( $ctx ) {

		$ctx = is_array( $ctx ) ? $ctx : array();

		list( $p1, $p2 ) = icon_psy_lens_narrative_text( $ctx );

		$comp = isset( $ctx['competency'] ) ? (string) $ctx['competency'] : 'Competency';
		$desc = isset( $ctx['description'] ) ? (string) $ctx['description'] : '';
		$descriptor = isset( $ctx['descriptor'] ) ? (string) $ctx['descriptor'] : '';

		$show_descriptor = true;
		if ( array_key_exists( 'show_descriptor', $ctx ) ) {
			$show_descriptor = (bool) $ctx['show_descriptor'];
		}

		// Prefer an explicit descriptor; otherwise show a short description snippet
		$focus_text = '';
		if ( $descriptor !== '' ) {
			$focus_text = icon_psy_lens_clean( $descriptor );
		} elseif ( $desc !== '' ) {
			$focus_text = icon_psy_lens_trim( icon_psy_lens_clean( $desc ), 220 );
		}

		// Build a subtle “Insight” card wrapper (inline styles for portability in WP + PDF HTML)
		$html  = '<div class="icon-psy-insight" style="border:1px solid #e5e7eb;background:#ffffff;border-radius:14px;padding:12px 12px;margin:10px 0 0;">';

		// Header row
		$html .= '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 0 10px;">';
		$html .=   '<div style="display:flex;align-items:center;gap:8px;min-width:0;">';
		$html .=     '<span style="display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;border:1px solid #d1fae5;background:#ecfdf5;color:#065f46;font-size:11px;font-weight:900;letter-spacing:0.08em;text-transform:uppercase;">Insight</span>';
		$html .=     '<span style="font-size:12px;font-weight:900;color:#0a3b34;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' . esc_html( $comp ) . '</span>';
		$html .=   '</div>';
		$html .= '</div>';

		// Optional descriptor block (separate from the narrative so it doesn’t “feel generic”)
		if ( $show_descriptor && $focus_text !== '' ) {
			$html .= '<div style="margin:0 0 10px;border-radius:12px;border:1px solid #eef2f7;background:#f9fafb;padding:10px;">';
			$html .=   '<div style="font-size:10px;font-weight:900;letter-spacing:0.10em;text-transform:uppercase;color:#6b7280;margin:0 0 6px;">Competency focus</div>';
			$html .=   '<div style="font-size:12px;color:#374151;line-height:1.55;">' . esc_html( $focus_text ) . '</div>';
			$html .= '</div>';
		}

		// Narrative paragraphs (exactly two)
		$html .= '<div class="icon-psy-insight-body">';
		$html .=   '<p style="margin:0 0 8px;font-size:12px;color:#4b5563;line-height:1.65;">' . esc_html( $p1 ) . '</p>';
		$html .=   '<p style="margin:0;font-size:12px;color:#4b5563;line-height:1.65;">' . esc_html( $p2 ) . '</p>';
		$html .= '</div>';

		$html .= '</div>';

		return $html;
	}
}
