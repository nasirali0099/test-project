Code to refactor
1) app/Http/Controllers/BookingController.php

Improvements Made:
Variable Naming: Changed variables to be more descriptive ($cuser to $user, etc.).
Response Handling: Returned JSON responses to ensure consistency.
Simplified Logic: Used except() in the update method instead of array_except.
Comments: Added comments to explain the purpose of each method.
Conditionals: Simplified and clarified conditionals where possible.
This refactoring improves readability, maintainability, and aligns with Laravel's best practices.

2) app/Repository/BookingRepository.php
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


Code to write tests
3) App/Helpers/TeHelper.php method willExpireAt
testWillExpireAtWhenDifferenceIsLessThanOrEqualTo90Minutes: Verifies that if the difference between due_time and created_at is 90 minutes or less, the returned time should be equal to due_time.

testWillExpireAtWhenDifferenceIsBetween90MinutesAnd24Hours: Checks that if the difference is between 90 minutes and 24 hours, the returned time should be created_at plus 90 minutes.

testWillExpireAtWhenDifferenceIsBetween24HoursAnd72Hours: Tests the scenario where the difference is between 24 and 72 hours, and ensures the returned time is created_at plus 16 hours.

testWillExpireAtWhenDifferenceIsMoreThan72Hours: Verifies that if the difference is more than 72 hours, the returned time should be due_time minus 48 hours.
4) App/Repository/UserRepository.php, method createOrUpdate

testCreateUser: Tests the creation of a new user with all relevant fields. It checks if the user, associated company, department, user meta, blacklist entries, and town associations are correctly created.

testUpdateUser: Tests updating an existing user. It verifies that the user's information, associated user meta, new towns, and language associations are updated correctly.