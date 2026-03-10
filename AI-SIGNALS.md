# AI-Generated Code Signals: Detection Guide

A catalog of telltale signs that source code was written or heavily assisted by an AI
language model (ChatGPT, Claude, Copilot, Gemini, etc.). Useful for code review,
WordPress.org submission preparation, and maintaining a human-authored codebase feel.

## 1. Comment & Documentation Signals

### 1a. Second-Person Pronouns in Comments

AI models are trained on instructional content and default to a tutorial voice.
Human developers rarely address "you" in code comments — they write notes to
themselves or future maintainers in third person or imperative mood.

**AI-style (tutorial voice):**
```php
// You can optionally enable this feature by setting the flag
// You should call this before rendering
// You need to pass a valid user ID here
// You may want to cache this result
```

**Human-style:**
```php
// Optional: enable by setting the flag
// Must be called before rendering
// Expects a valid user ID
// Consider caching this result
```

### 1b. Instructional / Hedging Phrases

These phrases come from LLM training on documentation, blog posts, and Stack Overflow
answers. They explain *to a reader* rather than *annotate for a maintainer*.

| AI Signal Phrase | Why It's Suspicious |
|---|---|
| "Note that..." / "Note:" | Tutorial voice — explaining to a student |
| "Make sure to..." | Instructional, not descriptive |
| "Ensure that..." / "Ensure we..." | Formal hedging typical of LLM output |
| "Remember to..." | Implies the reader is learning |
| "Keep in mind that..." | Conversational filler |
| "It's worth noting that..." | Classic LLM hedge phrase |
| "Be sure to..." | Instructional |
| "Don't forget to..." | Tutorial tone |
| "For safety..." / "For robustness..." | Over-justifying defensive code |

### 1c. Over-Explaining Comments (Restating the Code)

AI models compulsively narrate what code does, even when it's self-evident.
Human developers comment the *why*, not the *what*.

**AI-style:**
```php
// Loop through all the users
foreach ($users as $user) {
// Check if the user is active
if ($user->isActive()) {
// Return the user's email address
return $user->getEmail();
```

**Human-style:**
```php
// Only active users receive notifications
foreach ($users as $user) {
    if ($user->isActive()) {
        return $user->getEmail();
```

Common over-explaining prefixes:
- `// Get the...`
- `// Set the...`
- `// Check if...`
- `// Return the...`
- `// Create a new...`
- `// Initialize the...`
- `// Handle the...`
- `// Loop through...`

### 1d. "This" Sentence Comments

AI frequently writes comments that begin with "This" to describe what a block does
in full sentence form, as if writing documentation rather than inline notes.

```php
// This method handles the conversion of dates to timestamps
// This function validates the input parameters before processing
// This class is responsible for managing the event lifecycle
```

### 1e. AI Buzzwords

Certain words appear disproportionately in AI-generated text:

| Word | Why Suspicious |
|---|---|
| "robust" | Vague quality claim — humans say "handles edge case X" |
| "seamless" | Marketing language, not engineering language |
| "leverage" | Corporate jargon AI overuses; humans say "use" |
| "utilize" | Same — humans say "use" |
| "comprehensive" | Vague scope claim |
| "facilitate" | Unnecessarily formal |
| "streamline" | Marketing buzzword |
| "elegant" | Self-congratulatory |
| "straightforward" | Filler — if it were straightforward, no comment needed |
| "essentially" | Hedging word that adds nothing |

### 1f. Bookend / Section Marker Comments

AI often adds closing comments that mirror opening structures:

```php
// --- Event Processing Section ---
...code...
// --- End Event Processing Section ---

} // end foreach
} // end method
} // end class
```

## 2. Structural / Code Pattern Signals

### 2a. Excessive Defensive Coding

AI adds null checks, type checks, and guards even where the type system or framework
guarantees the value. This stems from the model not understanding runtime guarantees.

```php
// AI: checks what can't be null
$user = $this->getUser();  // return type is User (non-nullable)
if ($user === null) {       // impossible — but AI adds it anyway
    throw new \RuntimeException('User not found');
}
```

```javascript
// AI: redundant checks in React where lifecycle guarantees the ref
if (!calendarRef.current) return;
const inst = calendarRef.current.getInstance();
if (!inst) return;  // getInstance() always returns the instance if ref exists
```

### 2b. Over-Engineered Error Handling

AI wraps simple operations in try/catch even when they can't throw, or catches
exceptions only to log and re-throw identically.

```php
// AI: unnecessary try/catch around simple array access
try {
    $name = $config['name'];
} catch (\Exception $e) {
    $this->logger->error('Failed to get name: ' . $e->getMessage());
    throw $e;
}
```

**Silent catch blocks** (catch with only a comment) are also a signal:
```javascript
try {
    var data = JSON.parse(response);
} catch (e) {
    // Silently fail on parse error
}
```

### 2c. Uniform Repetitive Patterns

AI tends to generate code with mechanical uniformity — every method gets the same
structure, every branch gets the same handling, every variable gets the same
documentation format, regardless of whether it's needed.

```javascript
// AI: identical state reset in both branches instead of extracting defaults
if (event) {
    setTitle(event.title || '');
    setDescription(event.description || '');
    setLocation(event.location || '');
    // ...12 more identical lines
} else {
    setTitle('');
    setDescription('');
    setLocation('');
    // ...12 more identical lines
}
```

### 2d. Unnecessary Abstractions & Wrapper Methods

AI creates helper functions, utility classes, or abstraction layers for one-time
operations that don't warrant the indirection.

```php
// AI: wrapper that adds no value
private function getEventTitle(Event $event): string
{
    return $event->getTitle();
}
```

### 2e. Redundant Type Casting

Casting values that are already the correct type:

```php
$id = (int) $request->get_param('id');  // already validated as int by WP schema
```

### 2f. Placeholder / TODO Comments

AI often leaves TODO comments for features it was supposed to implement but didn't:

```php
// TODO: implement error handling
// TODO: add validation
// TODO: handle edge cases
```

## 3. Naming & Style Signals

### 3a. Overly Verbose Variable Names

AI favors extremely descriptive names that read like documentation:

```javascript
// AI-style
const userAuthenticationTokenExpirationTimestamp = Date.now();
const isCurrentlyProcessingEventDeletionRequest = false;

// Human-style
const tokenExpiry = Date.now();
const isDeletingEvent = false;
```

### 3b. Unnaturally Consistent Formatting

Human code has natural variation — some methods have comments, others don't; some
use shorthand, others are verbose. AI code is mechanically uniform: every method
has a JSDoc block, every function has the same error handling, every variable has
the same naming convention, even when unnecessary.

### 3c. Excessive JSDoc / PHPDoc

AI adds full docblocks to trivial methods where the signature tells the whole story:

```php
/**
 * Get the event ID.
 *
 * @return int The event ID.
 */
public function getId(): int
{
    return $this->id;
}
```

## 4. Test Code Signals

Test files are particularly prone to AI signals because AI generates them in bulk:

- Every test method has an identical setup/teardown pattern
- Comments narrate every assertion: `// Assert that the result is true`
- Test method names are excessively verbose: `testThatUserCanCreateEventWithValidDataAndReceiveSuccessResponse`
- Redundant assertions (asserting the same thing multiple ways)
- Comments like `// Arrange`, `// Act`, `// Assert` on every test

## 5. Prose / Documentation Signals

When AI writes markdown or inline documentation:

- Excessive use of em-dashes (—) as connectors
- Bullet-point-heavy structure where paragraphs would be more natural
- Section headings like "Understanding X", "The Importance of Y"
- Phrases: "Let's dive into", "In this section we'll explore", "At the end of the day"
- Every paragraph starts with a different transition word
- Overly balanced pros/cons lists

## Sources

- [How to Tell if Code is AI Generated - 5 Key Giveaways](https://diatomenterprises.com/how-to-tell-if-code-is-ai-generated/)
- [AI Code Detectors Developers Should Know in 2026](https://futuramo.com/blog/ai-code-detectors-2026/)
- [Comments in code written by AI - Matt Lacey](https://www.mrlacey.com/2024/04/comments-in-code-written-by-ai.html)
- [The Field Guide to AI Slop](https://www.ignorance.ai/p/the-field-guide-to-ai-slop)
- [AI vs human code gen report - CodeRabbit](https://www.coderabbit.ai/blog/state-of-ai-vs-human-code-generation-report)
- [Code Review in the Age of AI - Addy Osmani](https://addyo.substack.com/p/code-review-in-the-age-of-ai)
- [Why AI Can't Write Optimized Code: The Verbosity Problem](https://medium.com/@abhishek97.edu/why-ai-cant-write-optimized-code-the-verbosity-problem-and-how-to-solve-it-d9339bb9b290)
- [How well does Pangram work on AI code?](https://www.pangram.com/blog/can-ai-generated-code-be-detected)
- [How to Detect AI Generated Code in 2026](https://ccodelearner.com/how-to-detect-ai-generated-code/)
