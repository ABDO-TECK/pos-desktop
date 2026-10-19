-- Add missing indexes to expenses table to improve filtering performance
ALTER TABLE `expenses`
ADD INDEX `idx_expenses_date` (`expense_date`),
ADD INDEX `idx_expenses_category_id` (`category_id`);
