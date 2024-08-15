Code to refactor
app/Http/Controllers/BookingController.php

Improvements Made:
Variable Naming: Changed variables to be more descriptive ($cuser to $user, etc.).
Response Handling: Returned JSON responses to ensure consistency.
Simplified Logic: Used except() in the update method instead of array_except.
Comments: Added comments to explain the purpose of each method.
Conditionals: Simplified and clarified conditionals where possible.
This refactoring improves readability, maintainability, and aligns with Laravel's best practices.

app/Repository/BookingRepository.php
Key Improvements:
Variable Naming: Improved variable names for clarity (e.g., $cuser to $user, $noramlJobs to $normalJobs).
Code Simplification: Removed redundant comments and streamlined logic.
Commenting: Added comments to clarify the purpose of methods and key operations.
Consistent Response Format: Ensured that all methods return structured and consistent data.
Default Values: Used Laravel's built-in methods for handling default values ($request->get('page', 1)).
This refactor improves readability, maintainability, and code clarity, adhering to Laravel's best practices.
Logic Separation: Separated the logic into smaller, more focused methods for validation, job type determination, and response formatting.
Match Statement: Used the match statement for more readable and maintainable conditional logic.
Error Handling: Centralized error response creation to reduce repetition and improve consistency.