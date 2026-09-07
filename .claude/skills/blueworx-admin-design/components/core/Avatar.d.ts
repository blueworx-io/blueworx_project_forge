/**
 * Round user mark — a gravatar when there is one, initials when there is not.
 */
export interface AvatarProps {
  name?: string;
  src?: string;
  size?: number;
  className?: string;
}
export interface PersonProps {
  name: string;
  /** Second line: email, role or plan. */
  secondary?: string;
  src?: string;
  size?: number;
  className?: string;
}
export declare function Avatar(props: AvatarProps): JSX.Element;
export declare function Person(props: PersonProps): JSX.Element;
